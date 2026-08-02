<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedTinyInteger('delivery_flow_version')->nullable()->after('fulfillment_status')->index();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('can_deliver')->default(false)->after('role')->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['can_deliver']);
            $table->dropColumn('can_deliver');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['delivery_flow_version']);
            $table->dropColumn('delivery_flow_version');
        });
    }
};
