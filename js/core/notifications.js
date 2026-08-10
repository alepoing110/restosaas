// ==========================================================================
// NOTIFICATION SYSTEM
// ==========================================================================
(function (window) {
    'use strict';

    let soundEnabled = true;
    let browserNotificationsEnabled = false;
    let audioCtx = null;

    // Default notification sound (Web Audio API - no external file needed)
    function playNotificationSound() {
        if (!soundEnabled) return;
        try {
            if (!audioCtx) {
                audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            }
            if (audioCtx.state === 'suspended') audioCtx.resume();
            const oscillator = audioCtx.createOscillator();
            const gainNode = audioCtx.createGain();
            
            oscillator.connect(gainNode);
            gainNode.connect(audioCtx.destination);
            
            oscillator.type = 'sine';
            oscillator.frequency.setValueAtTime(800, audioCtx.currentTime);
            oscillator.frequency.setValueAtTime(600, audioCtx.currentTime + 0.1);
            
            gainNode.gain.setValueAtTime(0.3, audioCtx.currentTime);
            gainNode.gain.exponentialRampToValueAtTime(0.01, audioCtx.currentTime + 0.3);
            
            oscillator.start(audioCtx.currentTime);
            oscillator.stop(audioCtx.currentTime + 0.3);
        } catch (e) {
            console.warn('Could not play notification sound:', e);
        }
    }

    // Browser notification (if permitted)
    function showBrowserNotification(title, body, icon) {
        if (!browserNotificationsEnabled) return;
        if (!('Notification' in window)) return;
        
        if (Notification.permission === 'granted') {
            new Notification(title, { body, icon: icon || 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><text y=".9em" font-size="90">🍽️</text></svg>' });
        }
    }

    // Request browser notification permission
    async function requestPermission() {
        if (!('Notification' in window)) return false;
        if (Notification.permission === 'granted') {
            browserNotificationsEnabled = true;
            return true;
        }
        if (Notification.permission !== 'denied') {
            const permission = await Notification.requestPermission();
            browserNotificationsEnabled = permission === 'granted';
            return browserNotificationsEnabled;
        }
        return false;
    }

    // Show toast + sound + browser notification
    function notify(message, type = 'info', options = {}) {
        const { sound = true, browser = false, title, body } = options;
        
        // Always show toast
        if (typeof window.showToast === 'function') {
            window.showToast(message, type);
        }
        
        // Play sound if enabled
        if (sound && soundEnabled) {
            playNotificationSound();
        }
        
        // Browser notification if requested and permitted
        if (browser && browserNotificationsEnabled) {
            showBrowserNotification(title || 'RestoCloud', body || message);
        }
        
        // Update badge
        updateBadge();
    }

    // Update notification badge on sidebar
    function updateBadge() {
        const badge = document.getElementById('active-orders-count');
        const pendingOrders = window.state ? window.state.activeOrders.filter(o => o.status === 'pendiente').length : 0;
        if (badge) {
            badge.textContent = pendingOrders;
            badge.style.display = pendingOrders > 0 ? 'inline-block' : 'none';
        }
    }

    // Toggle sound
    function toggleSound() {
        soundEnabled = !soundEnabled;
        return soundEnabled;
    }

    // Get/set state
    function isSoundEnabled() {
        return soundEnabled;
    }

    function setSoundEnabled(enabled) {
        soundEnabled = enabled;
    }

    function isBrowserNotificationsEnabled() {
        return browserNotificationsEnabled;
    }

    const api = {
        notify,
        playNotificationSound,
        requestPermission,
        toggleSound,
        isSoundEnabled,
        setSoundEnabled,
        isBrowserNotificationsEnabled,
        updateBadge
    };

    window.Notifications = api;
})(window);
