# Local Area Network (LAN) Setup & Multi-Device Topology

## 1. Network Topology

```
                         [ Business Router / WiFi ]
                                    │
           ┌────────────────────────┼────────────────────────┐
           ▼                        ▼                        ▼
[ Local Business Hub ]     [ Cashier POS Tablet ]    [ Kitchen KDS Screen ]
  IP: 192.168.1.100          IP: 192.168.1.105         IP: 192.168.1.110
  Port: 8080                 Browser:                  Browser:
                             http://192.168.1.100:8080 http://192.168.1.100:8080
```

---

## 2. Router Configuration Recommendations
1. **Assign a Static DHCP Reservation** to the Local Business Hub computer (e.g. `192.168.1.100`).
2. **Isolate Guest WiFi from Staff LAN**: Ensure customer phones connect to a separate Guest WiFi with client isolation, while POS and Kitchen tablets reside on the internal Staff LAN.
3. **Firewall / Port Rules**: Open inbound port `8080` on the Local Hub computer's Windows Defender or `ufw` firewall:
   ```cmd
   netsh advfirewall firewall add rule name="QRPager Hub" dir=in action=allow protocol=TCP localport=8080
   ```

---

## 3. Device Setup Steps

### Cashier POS Tablet / Terminal:
1. Connect tablet to the Staff WiFi network.
2. Open Google Chrome, Safari, or Edge.
3. Navigate to: `http://192.168.1.100:8080` (or `https://app.yourdomain.com/orders/create` if online).
4. Tap the browser menu and select **"Add to Home Screen"** or **"Install App"** to install as a full-screen PWA.

### Kitchen KDS Display:
1. Mount a touchscreen monitor or tablet on the kitchen wall.
2. Navigate to: `http://192.168.1.100:8080` (or `https://app.yourdomain.com/kitchen`).
3. Tap **"⛶ Fullscreen"**.
4. Test the audio chime using the **"🔔 Test Audio Chime"** button to ensure staff can hear order alerts over kitchen background noise.
