/**
 * Mr. Cloak Advanced Bot Detection - JavaScript Fingerprinting
 *
 * Collects browser fingerprints and behavioral data to detect headless browsers,
 * automation tools, and sophisticated bots.
 */

(function() {
    'use strict';

    const MrCloakFingerprint = {

        /**
         * Generate canvas fingerprint
         */
        getCanvasFingerprint: function() {
            try {
                const canvas = document.createElement('canvas');
                const ctx = canvas.getContext('2d');

                canvas.width = 200;
                canvas.height = 50;

                // Draw text with various styles
                ctx.textBaseline = 'top';
                ctx.font = '14px "Arial"';
                ctx.textBaseline = 'alphabetic';
                ctx.fillStyle = '#f60';
                ctx.fillRect(125, 1, 62, 20);
                ctx.fillStyle = '#069';
                ctx.fillText('Mr.Cloak', 2, 15);
                ctx.fillStyle = 'rgba(102, 204, 0, 0.7)';
                ctx.fillText('Mr.Cloak', 4, 17);

                return canvas.toDataURL();
            } catch (e) {
                return null;
            }
        },

        /**
         * Generate WebGL fingerprint
         */
        getWebGLFingerprint: function() {
            try {
                const canvas = document.createElement('canvas');
                const gl = canvas.getContext('webgl') || canvas.getContext('experimental-webgl');

                if (!gl) return null;

                const debugInfo = gl.getExtension('WEBGL_debug_renderer_info');

                return {
                    vendor: gl.getParameter(gl.VENDOR),
                    renderer: gl.getParameter(gl.RENDERER),
                    version: gl.getParameter(gl.VERSION),
                    shadingLanguageVersion: gl.getParameter(gl.SHADING_LANGUAGE_VERSION),
                    unmaskedVendor: debugInfo ? gl.getParameter(debugInfo.UNMASKED_VENDOR_WEBGL) : null,
                    unmaskedRenderer: debugInfo ? gl.getParameter(debugInfo.UNMASKED_RENDERER_WEBGL) : null
                };
            } catch (e) {
                return null;
            }
        },

        /**
         * Generate audio context fingerprint
         */
        getAudioFingerprint: function() {
            try {
                const AudioContext = window.AudioContext || window.webkitAudioContext;
                if (!AudioContext) return null;

                const context = new AudioContext();
                const oscillator = context.createOscillator();
                const analyser = context.createAnalyser();
                const gainNode = context.createGain();
                const scriptProcessor = context.createScriptProcessor(4096, 1, 1);

                gainNode.gain.value = 0; // Mute
                oscillator.type = 'triangle';
                oscillator.connect(analyser);
                analyser.connect(scriptProcessor);
                scriptProcessor.connect(gainNode);
                gainNode.connect(context.destination);
                oscillator.start(0);

                const data = new Float32Array(analyser.frequencyBinCount);
                analyser.getFloatFrequencyData(data);

                oscillator.stop();
                context.close();

                // Create hash from frequency data
                let hash = 0;
                for (let i = 0; i < data.length; i++) {
                    hash += Math.abs(data[i]);
                }

                return hash.toString();
            } catch (e) {
                return null;
            }
        },

        /**
         * Get list of installed fonts
         */
        getFontList: function() {
            const baseFonts = ['monospace', 'sans-serif', 'serif'];
            const testFonts = [
                'Arial', 'Verdana', 'Times New Roman', 'Courier New', 'Georgia',
                'Palatino', 'Garamond', 'Bookman', 'Comic Sans MS', 'Trebuchet MS',
                'Impact', 'Helvetica', 'Tahoma', 'Geneva'
            ];

            const canvas = document.createElement('canvas');
            const ctx = canvas.getContext('2d');

            const testString = 'mmmmmmmmmmlli';
            const testSize = '72px';

            const baselines = {};
            baseFonts.forEach(baseFont => {
                ctx.font = testSize + ' ' + baseFont;
                baselines[baseFont] = ctx.measureText(testString).width;
            });

            const detected = [];
            testFonts.forEach(font => {
                let detected_font = false;
                baseFonts.forEach(baseFont => {
                    ctx.font = testSize + ' ' + font + ', ' + baseFont;
                    const width = ctx.measureText(testString).width;
                    if (width !== baselines[baseFont]) {
                        detected_font = true;
                    }
                });
                if (detected_font) {
                    detected.push(font);
                }
            });

            return detected;
        },

        /**
         * Get plugin information
         */
        getPlugins: function() {
            if (!navigator.plugins || navigator.plugins.length === 0) {
                return [];
            }

            const plugins = [];
            for (let i = 0; i < navigator.plugins.length; i++) {
                plugins.push({
                    name: navigator.plugins[i].name,
                    filename: navigator.plugins[i].filename
                });
            }
            return plugins;
        },

        /**
         * Detect automation tools and headless browsers
         */
        detectAutomation: function() {
            const signals = {
                webdriver: navigator.webdriver === true,
                chrome: !!(window.chrome && window.chrome.runtime),
                permissions: navigator.permissions ? true : false,
                plugins: navigator.plugins && navigator.plugins.length > 0,
                languages: navigator.languages && navigator.languages.length > 0,

                // Check for common automation frameworks
                phantom: !!(window.callPhantom || window._phantom),
                selenium: !!(window.document.documentElement.getAttribute('selenium') ||
                            window.document.documentElement.getAttribute('webdriver') ||
                            window.document.documentElement.getAttribute('driver')),
                puppeteer: !!(window.navigator.webdriver),

                // Check for headless browser indicators
                headlessChrome: (/HeadlessChrome/.test(window.navigator.userAgent)),
                userAgent: window.navigator.userAgent,
                platform: window.navigator.platform,

                // Check for inconsistencies
                touchPoints: navigator.maxTouchPoints || 0,
                hardwareConcurrency: navigator.hardwareConcurrency || 0,
                deviceMemory: navigator.deviceMemory || 0,

                // Screen properties
                screenResolution: screen.width + 'x' + screen.height,
                availableScreenResolution: screen.availWidth + 'x' + screen.availHeight,
                colorDepth: screen.colorDepth,
                pixelDepth: screen.pixelDepth,

                // Window properties
                windowSize: window.innerWidth + 'x' + window.innerHeight,
                documentSize: document.documentElement.clientWidth + 'x' + document.documentElement.clientHeight
            };

            // Calculate suspicion score
            let suspicionScore = 0;

            if (signals.webdriver) suspicionScore += 50;
            if (signals.phantom) suspicionScore += 50;
            if (signals.selenium) suspicionScore += 50;
            if (signals.headlessChrome) suspicionScore += 50;
            if (!signals.plugins || signals.plugins === 0) suspicionScore += 20;
            if (!signals.languages || signals.languages === 0) suspicionScore += 20;
            if (signals.hardwareConcurrency === 0) suspicionScore += 15;
            if (signals.touchPoints === 0 && /mobile/i.test(signals.userAgent)) suspicionScore += 25;

            signals.suspicionScore = Math.min(suspicionScore, 100);
            signals.isLikelyBot = suspicionScore >= 50;

            return signals;
        },

        /**
         * Track behavioral patterns
         */
        trackBehavior: function() {
            const behavior = {
                mouseMovements: 0,
                clicks: 0,
                scrolls: 0,
                keypresses: 0,
                touchEvents: 0,
                startTime: Date.now(),
                interactions: []
            };

            // Track mouse movements
            let mouseMoveCount = 0;
            document.addEventListener('mousemove', function() {
                mouseMoveCount++;
                behavior.mouseMovements = mouseMoveCount;
            }, { passive: true });

            // Track clicks
            let clickCount = 0;
            document.addEventListener('click', function() {
                clickCount++;
                behavior.clicks = clickCount;
                behavior.interactions.push({ type: 'click', time: Date.now() });
            }, { passive: true });

            // Track scrolling
            let scrollCount = 0;
            document.addEventListener('scroll', function() {
                scrollCount++;
                behavior.scrolls = scrollCount;
            }, { passive: true });

            // Track keypresses
            let keypressCount = 0;
            document.addEventListener('keypress', function() {
                keypressCount++;
                behavior.keypresses = keypressCount;
            }, { passive: true });

            // Track touch events
            let touchCount = 0;
            document.addEventListener('touchstart', function() {
                touchCount++;
                behavior.touchEvents = touchCount;
            }, { passive: true });

            return behavior;
        },

        /**
         * Check for timing inconsistencies
         */
        checkTiming: function() {
            const timing = window.performance && window.performance.timing;
            if (!timing) return null;

            return {
                domContentLoaded: timing.domContentLoadedEventEnd - timing.navigationStart,
                loadComplete: timing.loadEventEnd - timing.navigationStart,
                domReady: timing.domComplete - timing.domLoading,
                requestTime: timing.responseEnd - timing.requestStart
            };
        },

        /**
         * Generate complete fingerprint
         */
        generateFingerprint: function() {
            return {
                canvas: this.getCanvasFingerprint(),
                webgl: this.getWebGLFingerprint(),
                audio: this.getAudioFingerprint(),
                fonts: this.getFontList(),
                plugins: this.getPlugins(),
                automation: this.detectAutomation(),
                timing: this.checkTiming(),
                timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
                language: navigator.language,
                languages: navigator.languages,
                platform: navigator.platform,
                cookieEnabled: navigator.cookieEnabled,
                doNotTrack: navigator.doNotTrack,
                timestamp: Date.now()
            };
        },

        /**
         * Send fingerprint to server
         */
        send: function() {
            const fingerprint = this.generateFingerprint();
            const behavior = this.trackBehavior();

            // Store in sessionStorage for later retrieval
            try {
                sessionStorage.setItem('mrc_fingerprint', JSON.stringify(fingerprint));
                sessionStorage.setItem('mrc_behavior_start', Date.now().toString());
            } catch (e) {
                // SessionStorage might be disabled
            }

            // Send to server via AJAX
            const xhr = new XMLHttpRequest();
            xhr.open('POST', mrcFingerprint.ajaxUrl, true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

            const data = 'action=mrc_save_fingerprint&' +
                        'nonce=' + encodeURIComponent(mrcFingerprint.nonce) + '&' +
                        'fingerprint=' + encodeURIComponent(JSON.stringify(fingerprint));

            xhr.send(data);

            // Wait and send behavioral data after 5 seconds
            setTimeout(function() {
                const behaviorData = {
                    mouseMovements: behavior.mouseMovements,
                    clicks: behavior.clicks,
                    scrolls: behavior.scrolls,
                    keypresses: behavior.keypresses,
                    touchEvents: behavior.touchEvents,
                    timeOnPage: (Date.now() - behavior.startTime) / 1000,
                    interactions: behavior.interactions.slice(0, 10) // Limit to first 10
                };

                const xhr2 = new XMLHttpRequest();
                xhr2.open('POST', mrcFingerprint.ajaxUrl, true);
                xhr2.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

                const behaviorPayload = 'action=mrc_save_behavior&' +
                                       'nonce=' + encodeURIComponent(mrcFingerprint.nonce) + '&' +
                                       'behavior=' + encodeURIComponent(JSON.stringify(behaviorData));

                xhr2.send(behaviorPayload);
            }, 5000);
        }
    };

    // Initialize on page load
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            MrCloakFingerprint.send();
        });
    } else {
        MrCloakFingerprint.send();
    }

    // Expose to global scope for testing
    window.MrCloakFingerprint = MrCloakFingerprint;
})();
