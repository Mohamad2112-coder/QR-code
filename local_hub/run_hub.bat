@echo off
title Local Business Hub Server & Sync Daemon
echo ========================================================
echo   Starting QR Order Pager - Local Business Hub (LAN)
echo ========================================================

echo 1. Starting Local Business Hub Web Server on port 8080...
start "Hub Web Server" php -S 0.0.0.0:8080 hub_server.php

echo 2. Starting Background Cloud Sync Daemon...
start "Hub Sync Daemon" php hub_sync_worker.php

echo.
echo Local Business Hub is running!
echo Access from local LAN devices:
echo   - Cashier POS: http://localhost:8080/pos
echo   - Kitchen KDS: http://localhost:8080/kitchen
echo.
pause
