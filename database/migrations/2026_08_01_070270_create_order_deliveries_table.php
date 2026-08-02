<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('order_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $table->string('method', 30);
            $table->string('status', 30)->default('pending');
            $table->foreignId('pickup_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->timestamp('scheduled_at')->nullable();
            $table->string('time_window', 100)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('out_for_delivery_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('observation', 1000)->nullable();

            $table->string('recipient_snapshot_name')->nullable();
            $table->string('recipient_snapshot_phone', 40)->nullable();
            $table->string('recipient_snapshot_email')->nullable();
            $table->string('destination_address', 1000)->nullable();
            $table->string('destination_reference', 500)->nullable();
            $table->string('destination_district', 150)->nullable();
            $table->string('destination_province', 150)->nullable();
            $table->string('destination_department', 150)->nullable();
            $table->string('destination_postal_code', 30)->nullable();
            $table->json('destination_metadata')->nullable();

            $table->string('pickup_location_name')->nullable();
            $table->string('pickup_location_address', 1000)->nullable();
            $table->string('pickup_authorized_person')->nullable();
            $table->string('pickup_authorized_document', 100)->nullable();
            $table->timestamp('picked_up_at')->nullable();

            $table->foreignId('delivery_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('delivery_user_name')->nullable();
            $table->string('delivery_user_phone', 40)->nullable();
            $table->string('vehicle_plate', 40)->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('courier_code', 60)->nullable();
            $table->string('courier_name')->nullable();
            $table->string('courier_service')->nullable();
            $table->string('tracking_number')->nullable();
            $table->string('tracking_url', 2000)->nullable();
            $table->string('courier_reference')->nullable();
            $table->timestamp('handed_to_courier_at')->nullable();
            $table->decimal('courier_cost', 12, 2)->nullable();
            $table->string('external_status')->nullable();
            $table->json('provider_metadata')->nullable();
            $table->timestamp('last_synced_at')->nullable();

            $table->string('recipient_name')->nullable();
            $table->string('recipient_document_type', 30)->nullable();
            $table->string('recipient_document_number', 50)->nullable();
            $table->string('relationship_to_customer')->nullable();
            $table->string('confirmation_code', 100)->nullable();
            $table->string('recipient_signature_path', 1000)->nullable();
            $table->string('delivery_photo_path', 1000)->nullable();
            $table->string('delivery_constancy_path', 1000)->nullable();
            $table->string('delivery_notes', 1000)->nullable();
            $table->string('confirmation_method', 30)->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->string('confirmation_idempotency_key')->nullable()->unique();
            $table->timestamps();
            $table->index(['method', 'status']);
            $table->index(['delivery_user_id', 'status']);
            $table->index(['tracking_number', 'courier_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_deliveries');
    }
};
