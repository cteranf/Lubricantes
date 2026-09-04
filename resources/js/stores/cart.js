import { defineStore } from 'pinia';
import { normalizeCartQuantity } from '@/utils/cartQuantity';

export const useCartStore = defineStore('cart', {
    state: () => ({
        items: JSON.parse(localStorage.getItem('cartItems')) || [],
    }),
    getters: {
        count: (state) => state.items.reduce((acc, item) => acc + item.quantity, 0),
        total: (state) => state.items.reduce((acc, item) => acc + (item.price * item.quantity), 0),
    },
    actions: {
        addItem(product) {
            const existing = this.items.find(i => i.product_id === product.id);
            if (existing) {
                this.updateQuantity(existing.product_id, existing.quantity + 1);
                return;
            } else {
                const item = {
                    product_id: product.id,
                    name: product.name,
                    price: product.sale_price || product.price,
                    image: product.image_path,
                    quantity: 1,
                    product: product
                };
                item.quantity = normalizeCartQuantity(item.quantity, item).quantity;
                if (item.quantity > 0) this.items.push(item);
            }
            this.save();
        },
        removeItem(productId) {
            this.items = this.items.filter(i => i.product_id !== productId);
            this.save();
        },
        updateQuantity(productId, quantity) {
            const item = this.items.find(i => i.product_id === productId);
            if (item) {
                item.quantity = normalizeCartQuantity(quantity, item).quantity;
                if (item.quantity <= 0) this.removeItem(productId);
                else this.save();
            }
        },
        clear() {
            this.items = [];
            this.save();
            sessionStorage.removeItem('checkout:idempotency-token');
        },
        save() {
            localStorage.setItem('cartItems', JSON.stringify(this.items));
            // Optional: Sync with backend
        }
    }
});
