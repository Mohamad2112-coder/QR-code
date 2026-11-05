<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Core\Database;
use App\Core\UUID;
use App\Core\Session;
use App\Middleware\TenantMiddleware;
use App\Services\AuditService;

class ProductController
{
    public function index(Request $request, Response $response): void
    {
        $businessId = TenantMiddleware::getTenantId();
        $db = Database::getConnection();

        // Get categories
        $stmtCat = $db->prepare("SELECT * FROM product_categories WHERE business_id = ? ORDER BY display_order ASC, name ASC");
        $stmtCat->execute([$businessId]);
        $categories = $stmtCat->fetchAll();

        // Get products
        $stmtProd = $db->prepare("
            SELECT p.*, c.name as category_name
            FROM products p
            LEFT JOIN product_categories c ON p.category_id = c.id
            WHERE p.business_id = ? AND p.is_active = 1
            ORDER BY c.display_order ASC, p.display_order ASC, p.name ASC
        ");
        $stmtProd->execute([$businessId]);
        $products = $stmtProd->fetchAll();

        $html = View::render('products/index', [
            'title' => 'Product Catalog - QR Order Pager',
            'categories' => $categories,
            'products' => $products,
            'success' => Session::flash('success'),
            'error' => Session::flash('error')
        ]);

        $response->html($html)->send();
    }

    public function create(Request $request, Response $response): void
    {
        $businessId = TenantMiddleware::getTenantId();
        $name = trim((string)$request->input('name'));
        $categoryId = $request->input('category_id') ?: null;
        $description = trim((string)$request->input('description'));
        $price = max(0.00, (float)$request->input('price', 0.00));
        $displayOrder = (int)$request->input('display_order', 0);

        if (empty($name)) {
            Session::flash('error', 'Product name is required.');
            $response->redirect('/products')->send();
            return;
        }

        $db = Database::getConnection();
        $id = UUID::v4();
        $stmt = $db->prepare("
            INSERT INTO products (id, business_id, category_id, name, description, price, is_available, is_active, display_order, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, 1, 1, ?, NOW(), NOW())
        ");
        $stmt->execute([$id, $businessId, $categoryId, $name, $description, $price, $displayOrder]);

        AuditService::log('product_created', "Product '{$name}' created with price {$price}", $businessId);
        Session::flash('success', "Product '{$name}' added successfully.");
        $response->redirect('/products')->send();
    }

    public function update(Request $request, Response $response, array $params): void
    {
        $businessId = TenantMiddleware::getTenantId();
        $productId = $params['id'];
        $name = trim((string)$request->input('name'));
        $categoryId = $request->input('category_id') ?: null;
        $description = trim((string)$request->input('description'));
        $price = max(0.00, (float)$request->input('price', 0.00));
        $isAvailable = $request->input('is_available') !== null ? 1 : 0;
        $displayOrder = (int)$request->input('display_order', 0);

        $db = Database::getConnection();
        $stmt = $db->prepare("
            UPDATE products 
            SET category_id = ?, name = ?, description = ?, price = ?, is_available = ?, display_order = ?, updated_at = NOW()
            WHERE id = ? AND business_id = ?
        ");
        $stmt->execute([$categoryId, $name, $description, $price, $isAvailable, $displayOrder, $productId, $businessId]);

        AuditService::log('product_updated', "Product '{$name}' updated", $businessId);
        Session::flash('success', "Product updated successfully.");
        $response->redirect('/products')->send();
    }

    public function toggleAvailability(Request $request, Response $response, array $params): void
    {
        $businessId = TenantMiddleware::getTenantId();
        $productId = $params['id'];
        $db = Database::getConnection();

        $stmt = $db->prepare("UPDATE products SET is_available = IF(is_available = 1, 0, 1), updated_at = NOW() WHERE id = ? AND business_id = ?");
        $stmt->execute([$productId, $businessId]);

        if ($request->isAjaxOrJson()) {
            $response->json(['status' => 'success'])->send();
        } else {
            $response->redirect('/products')->send();
        }
    }

    public function delete(Request $request, Response $response, array $params): void
    {
        $businessId = TenantMiddleware::getTenantId();
        $productId = $params['id'];
        $db = Database::getConnection();

        // Soft-delete to preserve references
        $stmt = $db->prepare("UPDATE products SET is_active = 0, updated_at = NOW() WHERE id = ? AND business_id = ?");
        $stmt->execute([$productId, $businessId]);

        AuditService::log('product_deleted', "Product ID {$productId} archived", $businessId);
        Session::flash('success', "Product archived.");
        $response->redirect('/products')->send();
    }

    public function createCategory(Request $request, Response $response): void
    {
        $businessId = TenantMiddleware::getTenantId();
        $name = trim((string)$request->input('name'));
        $displayOrder = (int)$request->input('display_order', 0);

        if (!empty($name)) {
            $db = Database::getConnection();
            $stmt = $db->prepare("INSERT INTO product_categories (id, business_id, name, display_order, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, 1, NOW(), NOW())");
            $stmt->execute([UUID::v4(), $businessId, $name, $displayOrder]);
            Session::flash('success', "Category '{$name}' created.");
        }

        $response->redirect('/products')->send();
    }
}
