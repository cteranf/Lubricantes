import { defineStore } from 'pinia';
import api from '@/api';

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
                existing.quantity++;
            } else {
                this.items.push({
                    product_id: product.id,
                    name: product.name,
                    price: product.sale_price || product.price,
                    image: product.image_path,
                    quantity: 1,
                    product: product
                });
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
                item.quantity = quantity;
                if (item.quantity <= 0) this.removeItem(productId);
                else this.save();
            }
        },
        clear() {
            this.items = [];
            this.save();
        },
        save() {
            localStorage.setItem('cartItems', JSON.stringify(this.items));
            // Optional: Sync with backend
        }
    }
});
