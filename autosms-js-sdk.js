/**
 * AutoSMS Payment Hub - JavaScript SDK
 *
 * Production-ready JavaScript SDK for client-side integration with server validation.
 * Handles orders, payment verification, and real-time transaction monitoring.
 *
 * @version 1.0.0
 * @link https://www.musoftwares.com
 */

(function (global) {
    'use strict';

    /**
     * AutoSMS Client
     */
    class AutoSMSClient {
        constructor(config) {
            this.config = {
                apiUrl: config.apiUrl || window.location.origin,
                apiToken: config.apiToken || null,
                pollInterval: config.pollInterval || 5000,
                maxPollAttempts: config.maxPollAttempts || 60,
                onOrderCreated: config.onOrderCreated || null,
                onPaymentVerified: config.onPaymentVerified || null,
                onError: config.onError || null,
                debug: config.debug || false
            };

            this.activePolls = new Map();
            this.log('AutoSMS Client initialized', this.config);
        }

        /**
         * Create a new order
         * Server validates the order before creation
         */
        async createOrder(orderData) {
            try {
                this.log('Creating order', orderData);

                // Validate required fields
                this.validateOrderData(orderData);

                const response = await this.makeRequest('/api/auto-sms/orders/create', {
                    method: 'POST',
                    body: JSON.stringify(orderData)
                });

                if (response.success && response.order) {
                    this.log('Order created successfully', response.order);

                    if (this.config.onOrderCreated) {
                        this.config.onOrderCreated(response.order);
                    }

                    return {
                        success: true,
                        order: response.order,
                        paymentInstructions: response.payment_instructions
                    };
                }

                throw new Error(response.message || 'Failed to create order');

            } catch (error) {
                this.handleError('Order creation failed', error);
                return { success: false, error: error.message };
            }
        }

        /**
         * Verify payment for an order
         */
        async verifyOrderPayment(orderId, phoneNumber) {
            try {
                this.log('Verifying payment for order', { orderId, phoneNumber });

                const response = await this.makeRequest('/api/auto-sms/orders/verify-payment', {
                    method: 'POST',
                    body: JSON.stringify({
                        order_id: orderId,
                        phone_number: phoneNumber
                    })
                });

                if (response.success && response.payment_verified) {
                    this.log('Payment verified', response);

                    if (this.config.onPaymentVerified) {
                        this.config.onPaymentVerified(response.order, response.transaction);
                    }

                    return {
                        success: true,
                        order: response.order,
                        transaction: response.transaction
                    };
                }

                return {
                    success: false,
                    message: response.message || 'Payment not found'
                };

            } catch (error) {
                this.handleError('Payment verification failed', error);
                return { success: false, error: error.message };
            }
        }

        /**
         * Start polling for payment on an order
         */
        startPaymentPolling(orderId, phoneNumber, options = {}) {
            const pollId = `${orderId}-${phoneNumber}`;

            // Stop existing poll for this order/phone
            this.stopPaymentPolling(pollId);

            const pollConfig = {
                orderId,
                phoneNumber,
                interval: options.interval || this.config.pollInterval,
                maxAttempts: options.maxAttempts || this.config.maxPollAttempts,
                onSuccess: options.onSuccess || null,
                onError: options.onError || null,
                onTimeout: options.onTimeout || null,
                attempts: 0
            };

            this.log('Starting payment polling', pollConfig);

            const intervalId = setInterval(async () => {
                pollConfig.attempts++;
                this.log(`Polling attempt ${pollConfig.attempts}/${pollConfig.maxAttempts}`);

                const result = await this.verifyOrderPayment(orderId, phoneNumber);

                if (result.success && result.transaction) {
                    // Payment found!
                    this.stopPaymentPolling(pollId);

                    if (pollConfig.onSuccess) {
                        pollConfig.onSuccess(result.order, result.transaction);
                    }
                } else if (pollConfig.attempts >= pollConfig.maxAttempts) {
                    // Max attempts reached
                    this.stopPaymentPolling(pollId);

                    if (pollConfig.onTimeout) {
                        pollConfig.onTimeout();
                    }
                }
            }, pollConfig.interval);

            this.activePolls.set(pollId, {
                intervalId,
                config: pollConfig
            });

            return pollId;
        }

        /**
         * Stop payment polling
         */
        stopPaymentPolling(pollId) {
            const poll = this.activePolls.get(pollId);
            if (poll) {
                clearInterval(poll.intervalId);
                this.activePolls.delete(pollId);
                this.log('Stopped payment polling', pollId);
            }
        }

        /**
         * Stop all active polls
         */
        stopAllPolls() {
            this.activePolls.forEach((poll, pollId) => {
                clearInterval(poll.intervalId);
            });
            this.activePolls.clear();
            this.log('Stopped all polls');
        }

        /**
         * Get order status
         */
        async getOrderStatus(orderId) {
            try {
                const response = await this.makeRequest(`/api/auto-sms/orders/${orderId}`, {
                    method: 'GET'
                });

                return response.success ? response.order : null;

            } catch (error) {
                this.handleError('Failed to get order status', error);
                return null;
            }
        }

        /**
         * Cancel an order
         */
        async cancelOrder(orderId) {
            try {
                const response = await this.makeRequest(`/api/auto-sms/orders/${orderId}/cancel`, {
                    method: 'POST'
                });

                return response;

            } catch (error) {
                this.handleError('Failed to cancel order', error);
                return { success: false, error: error.message };
            }
        }

        /**
         * Validate order data
         */
        validateOrderData(orderData) {
            const required = ['customer_name', 'customer_phone', 'items', 'total_amount'];
            const missing = required.filter(field => !orderData[field]);

            if (missing.length > 0) {
                throw new Error(`Missing required fields: ${missing.join(', ')}`);
            }

            if (!Array.isArray(orderData.items) || orderData.items.length === 0) {
                throw new Error('Order must have at least one item');
            }

            if (orderData.total_amount <= 0) {
                throw new Error('Total amount must be greater than 0');
            }
        }

        /**
         * Make HTTP request to API
         */
        async makeRequest(endpoint, options = {}) {
            const url = `${this.config.apiUrl}${endpoint}`;

            const headers = {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            };

            // Add authentication
            if (this.config.apiToken) {
                headers['Authorization'] = `Bearer ${this.config.apiToken}`;
            }

            // Add CSRF token for same-origin requests
            const csrfToken = this.getCSRFToken();
            if (csrfToken) {
                headers['X-CSRF-TOKEN'] = csrfToken;
            }

            const fetchOptions = {
                method: options.method || 'GET',
                headers: headers,
                credentials: 'same-origin'
            };

            if (options.body) {
                fetchOptions.body = options.body;
            }

            const response = await fetch(url, fetchOptions);

            if (!response.ok) {
                const errorData = await response.json().catch(() => ({}));
                throw new Error(errorData.message || `HTTP ${response.status}: ${response.statusText}`);
            }

            return await response.json();
        }

        /**
         * Get CSRF token from meta tag
         */
        getCSRFToken() {
            const metaTag = document.querySelector('meta[name="csrf-token"]');
            return metaTag ? metaTag.getAttribute('content') : null;
        }

        /**
         * Handle errors
         */
        handleError(message, error) {
            console.error(`[AutoSMS] ${message}:`, error);

            if (this.config.onError) {
                this.config.onError(message, error);
            }
        }

        /**
         * Debug logging
         */
        log(...args) {
            if (this.config.debug) {
                console.log('[AutoSMS]', ...args);
            }
        }
    }

    /**
     * AutoSMS Checkout Widget
     * Complete checkout flow with order creation and payment verification
     */
    class AutoSMSCheckout {
        constructor(client, options = {}) {
            this.client = client;
            this.options = {
                container: options.container || 'autosms-checkout',
                theme: options.theme || 'light',
                currency: options.currency || 'EGP',
                locale: options.locale || 'en',
                showOrderSummary: options.showOrderSummary !== false,
                autoStartPolling: options.autoStartPolling !== false,
                ...options
            };

            this.currentOrder = null;
            this.currentPollId = null;
        }

        /**
         * Render checkout form
         */
        render(cart) {
            const container = this.getContainer();
            if (!container) {
                throw new Error(`Container #${this.options.container} not found`);
            }

            container.innerHTML = this.getCheckoutHTML(cart);
            this.attachEventListeners(cart);
        }

        /**
         * Get checkout HTML
         */
        getCheckoutHTML(cart) {
            const totalAmount = this.calculateTotal(cart);

            return `
                <div class="autosms-checkout ${this.options.theme}">
                    ${this.options.showOrderSummary ? this.getOrderSummaryHTML(cart, totalAmount) : ''}

                    <div class="autosms-checkout-form">
                        <h3>Customer Information</h3>

                        <div class="autosms-form-group">
                            <label for="autosms-customer-name">Full Name *</label>
                            <input type="text"
                                   id="autosms-customer-name"
                                   class="autosms-input"
                                   required
                                   placeholder="Enter your full name">
                        </div>

                        <div class="autosms-form-group">
                            <label for="autosms-customer-phone">Phone Number *</label>
                            <input type="tel"
                                   id="autosms-customer-phone"
                                   class="autosms-input"
                                   required
                                   placeholder="01015218548">
                            <small class="autosms-help-text">
                                This phone number will be used to verify your payment
                            </small>
                        </div>

                        <div class="autosms-form-group">
                            <label for="autosms-customer-email">Email (Optional)</label>
                            <input type="email"
                                   id="autosms-customer-email"
                                   class="autosms-input"
                                   placeholder="your@email.com">
                        </div>

                        <div class="autosms-form-group">
                            <label for="autosms-customer-address">Address (Optional)</label>
                            <textarea id="autosms-customer-address"
                                      class="autosms-input"
                                      rows="2"
                                      placeholder="Delivery address"></textarea>
                        </div>

                        <button type="button"
                                id="autosms-create-order-btn"
                                class="autosms-btn autosms-btn-primary">
                            Create Order (${totalAmount} ${this.options.currency})
                        </button>
                    </div>

                    <div class="autosms-payment-waiting" style="display: none;">
                        <div class="autosms-payment-instructions">
                            <h3>Payment Instructions</h3>
                            <div id="autosms-payment-details"></div>
                            <div class="autosms-polling-status">
                                <div class="autosms-spinner"></div>
                                <p>Waiting for payment confirmation...</p>
                                <small id="autosms-poll-counter"></small>
                            </div>
                        </div>
                    </div>

                    <div class="autosms-payment-success" style="display: none;">
                        <div class="autosms-success-icon">✓</div>
                        <h3>Payment Confirmed!</h3>
                        <p id="autosms-success-message"></p>
                    </div>
                </div>
            `;
        }

        /**
         * Get order summary HTML
         */
        getOrderSummaryHTML(cart, totalAmount) {
            const itemsHTML = cart.map(item => `
                <div class="autosms-cart-item">
                    <span class="item-name">${item.name} × ${item.quantity}</span>
                    <span class="item-price">${item.price * item.quantity} ${this.options.currency}</span>
                </div>
            `).join('');

            return `
                <div class="autosms-order-summary">
                    <h3>Order Summary</h3>
                    <div class="autosms-cart-items">
                        ${itemsHTML}
                    </div>
                    <div class="autosms-cart-total">
                        <span>Total</span>
                        <span class="total-amount">${totalAmount} ${this.options.currency}</span>
                    </div>
                </div>
            `;
        }

        /**
         * Attach event listeners
         */
        attachEventListeners(cart) {
            const createOrderBtn = document.getElementById('autosms-create-order-btn');
            if (createOrderBtn) {
                createOrderBtn.addEventListener('click', () => this.handleCreateOrder(cart));
            }
        }

        /**
         * Handle order creation
         */
        async handleCreateOrder(cart) {
            const btn = document.getElementById('autosms-create-order-btn');
            const originalText = btn.textContent;

            try {
                // Disable button
                btn.disabled = true;
                btn.textContent = 'Creating order...';

                // Get form data
                const orderData = {
                    customer_name: document.getElementById('autosms-customer-name').value.trim(),
                    customer_phone: document.getElementById('autosms-customer-phone').value.trim(),
                    customer_email: document.getElementById('autosms-customer-email').value.trim(),
                    customer_address: document.getElementById('autosms-customer-address').value.trim(),
                    items: cart.map(item => ({
                        name: item.name,
                        quantity: item.quantity,
                        price: item.price,
                        sku: item.sku || null
                    })),
                    total_amount: this.calculateTotal(cart),
                    currency: this.options.currency
                };

                // Create order
                const result = await this.client.createOrder(orderData);

                if (result.success) {
                    this.currentOrder = result.order;
                    this.showPaymentInstructions(result.order, result.paymentInstructions);

                    // Start polling if auto-start is enabled
                    if (this.options.autoStartPolling) {
                        this.startPaymentPolling(result.order);
                    }
                } else {
                    throw new Error(result.error || 'Failed to create order');
                }

            } catch (error) {
                alert('Error: ' + error.message);
                btn.disabled = false;
                btn.textContent = originalText;
            }
        }

        /**
         * Show payment instructions
         */
        showPaymentInstructions(order, instructions) {
            // Hide form, show payment waiting
            document.querySelector('.autosms-checkout-form').style.display = 'none';
            document.querySelector('.autosms-payment-waiting').style.display = 'block';

            const detailsDiv = document.getElementById('autosms-payment-details');
            detailsDiv.innerHTML = `
                <div class="autosms-payment-info">
                    <p><strong>Order ID:</strong> #${order.id}</p>
                    <p><strong>Amount to Pay:</strong> ${order.total_amount} ${order.currency}</p>
                    <p><strong>Payment Phone:</strong> ${order.customer_phone}</p>
                    ${instructions ? `<div class="autosms-instructions">${instructions}</div>` : ''}
                </div>
            `;
        }

        /**
         * Start payment polling
         */
        startPaymentPolling(order) {
            let attempts = 0;
            const maxAttempts = this.client.config.maxPollAttempts;

            this.currentPollId = this.client.startPaymentPolling(
                order.id,
                order.customer_phone,
                {
                    onSuccess: (updatedOrder, transaction) => {
                        this.showPaymentSuccess(updatedOrder, transaction);
                    },
                    onTimeout: () => {
                        this.showPaymentTimeout();
                    }
                }
            );

            // Update counter
            const updateCounter = setInterval(() => {
                attempts++;
                const counterEl = document.getElementById('autosms-poll-counter');
                if (counterEl) {
                    counterEl.textContent = `Checking... (${attempts}/${maxAttempts})`;
                }

                if (!this.currentPollId || attempts >= maxAttempts) {
                    clearInterval(updateCounter);
                }
            }, this.client.config.pollInterval);
        }

        /**
         * Show payment success
         */
        showPaymentSuccess(order, transaction) {
            document.querySelector('.autosms-payment-waiting').style.display = 'none';
            document.querySelector('.autosms-payment-success').style.display = 'block';

            const messageEl = document.getElementById('autosms-success-message');
            messageEl.innerHTML = `
                <p>Order #${order.id} has been confirmed!</p>
                <p>Payment: ${transaction.amount} ${transaction.currency}</p>
                <p>Transaction Date: ${new Date(transaction.transaction_date).toLocaleString()}</p>
            `;

            // Trigger custom event
            this.triggerEvent('payment-success', { order, transaction });
        }

        /**
         * Show payment timeout
         */
        showPaymentTimeout() {
            const pollingStatus = document.querySelector('.autosms-polling-status');
            if (pollingStatus) {
                pollingStatus.innerHTML = `
                    <p style="color: #f59e0b;">⚠️ Payment not detected yet</p>
                    <p>Please contact support with your Order ID: #${this.currentOrder.id}</p>
                    <button onclick="location.reload()" class="autosms-btn">Try Again</button>
                `;
            }
        }

        /**
         * Calculate cart total
         */
        calculateTotal(cart) {
            return cart.reduce((total, item) => total + (item.price * item.quantity), 0);
        }

        /**
         * Get container element
         */
        getContainer() {
            const container = document.getElementById(this.options.container);
            if (!container) {
                console.error(`Container #${this.options.container} not found`);
            }
            return container;
        }

        /**
         * Trigger custom event
         */
        triggerEvent(eventName, data) {
            const event = new CustomEvent(`autosms-${eventName}`, {
                detail: data,
                bubbles: true
            });
            document.dispatchEvent(event);
        }

        /**
         * Destroy checkout
         */
        destroy() {
            if (this.currentPollId) {
                this.client.stopPaymentPolling(this.currentPollId);
            }
            const container = this.getContainer();
            if (container) {
                container.innerHTML = '';
            }
        }
    }

    // Export to global scope
    global.AutoSMSClient = AutoSMSClient;
    global.AutoSMSCheckout = AutoSMSCheckout;

    // AMD support
    if (typeof define === 'function' && define.amd) {
        define('autosms', [], function () {
            return { AutoSMSClient, AutoSMSCheckout };
        });
    }

    // CommonJS support
    if (typeof module === 'object' && module.exports) {
        module.exports = { AutoSMSClient, AutoSMSCheckout };
    }

})(typeof window !== 'undefined' ? window : this);

