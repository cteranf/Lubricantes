<?php

namespace Tests\Feature;

use Tests\TestCase;

class CheckoutPickupFrontendStateTest extends TestCase
{
    public function test_checkout_distinguishes_pickup_loading_error_empty_and_available_states(): void
    {
        $source = file_get_contents(resource_path('js/views/Checkout.vue'));

        $this->assertStringContainsString('const loadingPickupBranches = ref(false);', $source);
        $this->assertStringContainsString('const pickupBranchesLoaded = ref(false);', $source);
        $this->assertStringContainsString("const pickupBranchesError = ref('');", $source);
        $this->assertStringContainsString('const pickupAvailable = computed(() => pickupBranchesLoaded.value && !pickupBranchesError.value && pickupBranches.value.length > 0);', $source);
        $this->assertStringContainsString(':disabled="!pickupAvailable"', $source);
        $this->assertStringContainsString('No hay sedes disponibles para recojo en este momento.', $source);
        $this->assertStringContainsString('No pudimos consultar las sedes de recojo. Intenta nuevamente.', $source);
        $this->assertStringContainsString('@click="loadPickupBranches">Reintentar</button>', $source);
    }

    public function test_checkout_consumes_direct_array_and_pickup_does_not_depend_on_delivery_state(): void
    {
        $source = file_get_contents(resource_path('js/views/Checkout.vue'));

        $this->assertStringContainsString("const response = await api.get('/checkout/pickup-branches');", $source);
        $this->assertStringContainsString('if (!Array.isArray(response.data))', $source);
        $this->assertStringContainsString('pickupBranches.value = response.data;', $source);
        $this->assertStringContainsString("const canSubmitPickup = computed(() => form.value.delivery_type === 'pickup' && pickupAvailable.value && Boolean(selectedBranch.value));", $source);
        $this->assertStringContainsString("else { quote.value = null; quoteLoading.value = false; quoteRequest++; void refreshTaxPreview('0.00'); }", $source);
    }

    public function test_checkout_shows_offer_breakdown_and_adds_shipping_to_discounted_subtotal_once(): void
    {
        $source = file_get_contents(resource_path('js/views/Checkout.vue'));
        $summaryStart = strpos($source, '<div class="mt-6 space-y-2 border-t pt-5 text-sm">');
        $this->assertNotFalse($summaryStart);
        $summaryEnd = strpos($source, '</aside>', $summaryStart);
        $this->assertNotFalse($summaryEnd);
        $summary = substr($source, $summaryStart, $summaryEnd - $summaryStart);

        $this->assertStringContainsString("import { cartItemPriceBreakdown, summarizeCartPrices } from '@/utils/cartPricing.mjs'", $source);
        $this->assertStringContainsString('const checkoutPriceSummary = computed(() => summarizeCartPrices(cartStore.items));', $source);
        $this->assertStringContainsString('itemPriceBreakdown(item).regularPrice', $source);
        $this->assertStringContainsString('itemPriceBreakdown(item).appliedPrice', $source);
        $this->assertStringContainsString('itemPriceBreakdown(item).unitSavings', $source);
        $this->assertStringContainsString('v-if="checkoutPriceSummary.hasSavings"', $summary);
        $this->assertStringContainsString('Subtotal sin ofertas', $summary);
        $this->assertStringContainsString('Ahorro en ofertas', $summary);
        $this->assertStringContainsString('Subtotal de productos', $summary);
        $this->assertStringNotContainsString('Descuentos comerciales', $summary);
        $this->assertStringContainsString('checkoutPriceSummary.productSubtotal', $source);
        $this->assertStringContainsString('toMinor(quote.value.shipping_amount)', $source);
        $this->assertStringNotContainsString('toMinor(checkoutPriceSummary.value.savings)', $source);
        $this->assertStringContainsString('applied_price: line.price', $source);
        $this->assertStringContainsString('cartStore.applyPriceQuotes(', $source);
        $this->assertStringContainsString('priceChangePrompt.value = {', $source);
        $this->assertStringContainsString('acceptedPriceConfirmationToken.value = null;', $source);
    }
}
