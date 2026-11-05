// Multi-Tenant QR Order Pager Client App JavaScript

// 1. Audio Notification Synthesizer (Zero external dependencies)
class SoundNotifier {
    static playChime(type = 'ready') {
        try {
            const ctx = new (window.AudioContext || window.webkitAudioContext)();
            if (ctx.state === 'suspended') {
                ctx.resume();
            }

            const now = ctx.currentTime;
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();

            osc.connect(gain);
            gain.connect(ctx.destination);

            if (type === 'ready') {
                // Happy uplifting 3-tone chime for Ready status
                osc.type = 'triangle';
                osc.frequency.setValueAtTime(523.25, now); // C5
                osc.frequency.setValueAtTime(659.25, now + 0.12); // E5
                osc.frequency.setValueAtTime(783.99, now + 0.24); // G5
                osc.frequency.setValueAtTime(1046.50, now + 0.36); // C6

                gain.gain.setValueAtTime(0, now);
                gain.gain.linearRampToValueAtTime(0.3, now + 0.05);
                gain.gain.exponentialRampToValueAtTime(0.001, now + 0.9);

                osc.start(now);
                osc.stop(now + 0.9);
            } else if (type === 'new_order') {
                // Bell tone for kitchen incoming order
                osc.type = 'sine';
                osc.frequency.setValueAtTime(880, now); // A5
                osc.frequency.setValueAtTime(1174.66, now + 0.15); // D6

                gain.gain.setValueAtTime(0, now);
                gain.gain.linearRampToValueAtTime(0.4, now + 0.03);
                gain.gain.exponentialRampToValueAtTime(0.001, now + 0.7);

                osc.start(now);
                osc.stop(now + 0.7);
            }
        } catch (e) {
            console.log('Audio playback prevented or unsupported:', e);
        }
    }
}

// 2. Register Service Worker for PWA
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').then((reg) => {
            console.log('ServiceWorker registered:', reg.scope);
        }).catch((err) => {
            console.log('ServiceWorker registration error:', err);
        });
    });
}

// 3. Web Push API Client Helper
class PushManagerClient {
    static async getVapidPublicKey() {
        const res = await fetch('/api/vapid-key');
        const data = await res.json();
        return data.public_key;
    }

    static urlBase64ToUint8Array(base64String) {
        const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
        const base64 = (base64String + padding).replace(/\-/g, '+').replace(/_/g, '/');
        const rawData = window.atob(base64);
        const outputArray = new Uint8Array(rawData.length);
        for (let i = 0; i < rawData.length; ++i) {
            outputArray[i] = rawData.charCodeAt(i);
        }
        return outputArray;
    }

    static async subscribeToOrder(orderToken) {
        if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
            return { success: false, reason: 'unsupported' };
        }

        const permission = await Notification.requestPermission();
        if (permission !== 'granted') {
            return { success: false, reason: 'denied' };
        }

        const vapidPublicKey = await this.getVapidPublicKey();
        const convertedVapidKey = this.urlBase64ToUint8Array(vapidPublicKey);

        const registration = await navigator.serviceWorker.ready;
        const subscription = await registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: convertedVapidKey
        });

        const subJson = subscription.toJSON();
        const response = await fetch('/api/customer/push-subscribe', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                token: orderToken,
                endpoint: subJson.endpoint,
                p256dh: subJson.keys.p256dh,
                auth: subJson.keys.auth
            })
        });

        return { success: response.ok, reason: 'subscribed' };
    }
}

// 4. Online / Offline Monitoring Indicator
function updateNetworkStatus() {
    const badges = document.querySelectorAll('.network-status-badge');
    badges.forEach(badge => {
        if (navigator.onLine) {
            badge.className = 'badge badge-online network-status-badge';
            badge.innerText = '● Cloud Online';
        } else {
            badge.className = 'badge badge-offline network-status-badge';
            badge.innerText = '▲ Offline Mode (Local)';
        }
    });
}
window.addEventListener('online', updateNetworkStatus);
window.addEventListener('offline', updateNetworkStatus);
document.addEventListener('DOMContentLoaded', updateNetworkStatus);
