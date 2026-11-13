import { defineStore } from 'pinia';
import { normalizeCartQuantity } from '@/utils/cartQuantity';
import { productImageDimensions, productImageSrcset, productImageVariants, resolveProductImages } from '@/utils/productPresentation';

export const useCartStore = defineStore('cart', {
    state: () => ({
        items: JSON.parse(localStorage.getItem('cartItems')) || [],
        drawerOpen: false,
        drawerNotice: null,
        addingProducts: {},
        lineOperations: {},
        returnFocusTarget: null,
        noticeSequence: 0,
    }),
    getters: {
        count: (state) => state.items.reduce((acc, item) => acc + item.quantity, 0),
        total: (state) => state.items.reduce((acc, item) => acc + (item.price * item.quantity), 0),
    },
    actions: {
        openDrawer(trigger = null) {
            this.returnFocusTarget = trigger || (typeof document !== 'undefined' ? document.activeElement : null);
            this.drawerNotice = null;
            this.drawerOpen = true;
        },
        closeDrawer({ restoreFocus = true } = {}) {
            this.drawerOpen = false;
            this.drawerNotice = null;
            if (!restoreFocus) this.returnFocusTarget = null;
        },
        restoreDrawerFocus() {
            const target = this.returnFocusTarget;
            this.returnFocusTarget = null;
            if (target?.isConnected) target.focus();
        },
        addItem(product, quantity = 1, trigger = null) {
            if (!product?.id || this.addingProducts[product.id] || this.lineOperations[product.id]) return false;
            this.addingProducts[product.id] = true;
            const existing = this.items.find(item => item.product_id === product.id);
            const stock = Number(product.stock);
            const requested = Math.max(1, Math.trunc(Number(quantity) || 1));
            const currentQuantity = Number(existing?.quantity || 0);
            if (Number.isFinite(stock) && stock >= 0 && currentQuantity + requested > stock) {
                this.addingProducts[product.id] = false;
                return false;
            }

            const previousItems = this.items.map(item => ({ ...item }));
            try {
                if (existing) {
                    existing.quantity = normalizeCartQuantity(existing.quantity + requested, existing).quantity;
                } else {
                    const item = {
                        product_id: product.id,
                        name: product.name,
                        price: product.sale_price || product.price,
                        image: productImageVariants(product).find(image => image.key === 'thumbnail')?.url
                            || productImageVariants(product).find(image => image.key === 'card')?.url
                            || resolveProductImages(product)[0] || null,
                        image_srcset: productImageSrcset(product, ['thumbnail', 'card']),
                        image_sizes: '160px',
                        image_dimensions: productImageDimensions(product, ['thumbnail', 'card']),
                        quantity: requested,
                        product: product
                    };
                    item.quantity = normalizeCartQuantity(item.quantity, item).quantity;
                    if (item.quantity > 0) this.items.push(item);
                }
                this.save();
                this.returnFocusTarget = trigger || (typeof document !== 'undefined' ? document.activeElement : null);
                this.drawerOpen = true;
                this.drawerNotice = { id: ++this.noticeSequence, productId: product.id, message: 'Producto agregado correctamente' };
                return true;
            } catch {
                this.items = previousItems;
                throw new Error('No fue posible actualizar el carrito. Inténtalo nuevamente.');
            } finally {
                this.addingProducts[product.id] = false;
            }
        },
        removeItem(productId) {
            if (this.lineOperations[productId]) return false;
            this.lineOperations[productId] = true;
            const previousItems = this.items;
            try {
                this.items = this.items.filter(item => item.product_id !== productId);
                this.save();
                return true;
            } catch {
                this.items = previousItems;
                throw new Error('No fue posible actualizar el carrito. Inténtalo nuevamente.');
            } finally {
                this.lineOperations[productId] = false;
            }
        },
        updateQuantity(productId, quantity) {
            if (this.lineOperations[productId]) return false;
            this.lineOperations[productId] = true;
            const item = this.items.find(entry => entry.product_id === productId);
            const previousQuantity = item?.quantity;
            try {
                if (item) {
                    item.quantity = normalizeCartQuantity(quantity, item).quantity;
                    if (item.quantity <= 0) this.removeItem(productId);
                    else this.save();
                }
                return Boolean(item);
            } catch {
                if (item) item.quantity = previousQuantity;
                throw new Error('No fue posible actualizar el carrito. Inténtalo nuevamente.');
            } finally {
                this.lineOperations[productId] = false;
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
