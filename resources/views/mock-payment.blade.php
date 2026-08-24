<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pasarela Segura — Simulador Visa / Mastercard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .card-gradient-visa {
            background: linear-gradient(135deg, #0d1b2a 0%, #1b263b 50%, #1e3a8a 100%);
        }
        .card-gradient-mc {
            background: linear-gradient(135deg, #2b2d42 0%, #4a4e69 50%, #8d99ae 100%);
        }
        .chip {
            background: linear-gradient(135deg, #d4af37 0%, #f3e5ab 50%, #aa771c 100%);
        }
    </style>
</head>
<body class="bg-slate-900 text-slate-100 min-h-screen flex flex-col justify-between antialiased">
    <!-- Header -->
    <header class="border-b border-slate-800 bg-slate-950/80 backdrop-blur px-6 py-4">
        <div class="max-w-5xl mx-auto flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="bg-blue-600/20 text-blue-400 p-2 rounded-lg border border-blue-500/30">
                    <i class="fa-solid fa-shield-halved text-xl"></i>
                </div>
                <div>
                    <h1 class="font-bold text-lg leading-tight tracking-wide flex items-center gap-2">
                        <span>Visa & Mastercard Checkout</span>
                        <span class="text-xs bg-amber-500/20 text-amber-300 px-2 py-0.5 rounded border border-amber-500/30 font-mono">Simulador Demo</span>
                    </h1>
                    <p class="text-xs text-slate-400">Entorno seguro de simulación de pagos con tarjeta</p>
                </div>
            </div>
            <div class="text-right hidden sm:block">
                <span class="text-xs text-slate-400 uppercase tracking-wider block">Pedido</span>
                <span class="font-mono font-bold text-emerald-400 text-base">#{{ $order->id }}</span>
            </div>
        </div>
    </header>

    <!-- Main Body -->
    <main class="max-w-5xl mx-auto w-full p-4 sm:p-6 my-auto">
        <div class="grid lg:grid-cols-12 gap-8 items-start">
            
            <!-- Left Column: Interactive Card & Simulation Controls -->
            <div class="lg:col-span-7 space-y-6">
                <!-- Virtual Card Container -->
                <div class="relative w-full max-w-md mx-auto aspect-[1.586/1] rounded-2xl p-6 shadow-2xl transition-all duration-300 transform select-none card-gradient-visa text-white border border-white/10" id="cardElement">
                    <div class="flex justify-between items-start">
                        <div class="flex items-center gap-3">
                            <div class="w-12 h-9 rounded-md chip shadow-inner flex items-center justify-center">
                                <div class="w-8 h-6 border border-yellow-900/40 rounded-sm grid grid-cols-2 gap-0.5 opacity-60">
                                    <div class="border-r border-b border-yellow-900/40"></div>
                                    <div class="border-b border-yellow-900/40"></div>
                                    <div class="border-r border-yellow-900/40"></div>
                                    <div></div>
                                </div>
                            </div>
                            <i class="fa-solid fa-wifi rotate-90 text-slate-300 text-lg opacity-80"></i>
                        </div>
                        <div id="cardBrandLogo" class="font-black italic text-2xl tracking-wider text-white">
                            VISA
                        </div>
                    </div>

                    <div class="mt-8 mb-4">
                        <div class="text-xs font-mono text-slate-300 uppercase tracking-widest mb-1">Número de tarjeta</div>
                        <div id="cardDisplayNumber" class="font-mono text-xl sm:text-2xl tracking-widest font-semibold text-white drop-shadow">
                            4557 •••• •••• 8820
                        </div>
                    </div>

                    <div class="flex justify-between items-end text-xs font-mono">
                        <div>
                            <span class="text-slate-400 block text-[10px] uppercase">Titular</span>
                            <span id="cardDisplayName" class="font-sans font-semibold text-sm tracking-wide text-white uppercase truncate max-w-[180px] block">
                                {{ $order->user->name ?? 'JUAN PEREZ' }}
                            </span>
                        </div>
                        <div class="text-right">
                            <span class="text-slate-400 block text-[10px] uppercase">Vence</span>
                            <span id="cardDisplayExpiry" class="font-semibold text-sm text-white">
                                12/28
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Scenario Presets -->
                <div class="bg-slate-800/80 border border-slate-700 rounded-xl p-4 sm:p-5 shadow-lg">
                    <h3 class="text-sm font-semibold text-slate-300 mb-3 flex items-center gap-2">
                        <i class="fa-solid fa-wand-magic-sparkles text-amber-400"></i>
                        <span>Escenarios rápidos de prueba</span>
                    </h3>
                    <div class="grid grid-cols-2 sm:grid-cols-2 gap-2 text-xs">
                        <button type="button" onclick="setScenario('visa_approved')" class="bg-emerald-950/60 hover:bg-emerald-900/80 text-emerald-300 border border-emerald-700/50 p-2.5 rounded-lg text-left transition flex items-center gap-2">
                            <i class="fa-brands fa-cc-visa text-lg text-emerald-400"></i>
                            <div>
                                <strong class="block">Visa Aprobada</strong>
                                <span class="text-[11px] opacity-75">Transacción exitosa</span>
                            </div>
                        </button>
                        <button type="button" onclick="setScenario('mastercard_approved')" class="bg-emerald-950/60 hover:bg-emerald-900/80 text-emerald-300 border border-emerald-700/50 p-2.5 rounded-lg text-left transition flex items-center gap-2">
                            <i class="fa-brands fa-cc-mastercard text-lg text-emerald-400"></i>
                            <div>
                                <strong class="block">Mastercard Aprobada</strong>
                                <span class="text-[11px] opacity-75">Transacción exitosa</span>
                            </div>
                        </button>
                        <button type="button" onclick="setScenario('insufficient_funds')" class="bg-rose-950/50 hover:bg-rose-900/70 text-rose-300 border border-rose-700/50 p-2.5 rounded-lg text-left transition flex items-center gap-2">
                            <i class="fa-solid fa-hand-holding-dollar text-rose-400"></i>
                            <div>
                                <strong class="block">Sin fondos</strong>
                                <span class="text-[11px] opacity-75">Rechazo por saldo</span>
                            </div>
                        </button>
                        <button type="button" onclick="setScenario('declined')" class="bg-rose-950/50 hover:bg-rose-900/70 text-rose-300 border border-rose-700/50 p-2.5 rounded-lg text-left transition flex items-center gap-2">
                            <i class="fa-solid fa-ban text-rose-400"></i>
                            <div>
                                <strong class="block">Denegada / Expirada</strong>
                                <span class="text-[11px] opacity-75">Rechazo por banco</span>
                            </div>
                        </button>
                    </div>
                </div>

                <!-- Form Controls (Visual Only - Safe) -->
                <form action="{{ $processUrl }}" method="POST" id="paymentForm" class="space-y-4">
                    @csrf
                    <input type="hidden" name="scenario" id="selectedScenario" value="approved">
                    <input type="hidden" name="action" id="selectedAction" value="approve">

                    <div class="bg-slate-800/50 border border-slate-700/80 rounded-xl p-4 sm:p-5 space-y-4">
                        <div>
                            <label class="block text-xs font-semibold text-slate-300 mb-1">Nombre en la tarjeta</label>
                            <input type="text" id="inputCardName" value="{{ $order->user->name ?? 'JUAN PEREZ' }}" oninput="updateVisualCard()" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-100 focus:outline-none focus:border-blue-500" placeholder="Nombre completo">
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-semibold text-slate-300 mb-1">Vencimiento (MM/AA)</label>
                                <input type="text" id="inputExpiry" value="12/28" maxlength="5" oninput="updateVisualCard()" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm font-mono text-slate-100 focus:outline-none focus:border-blue-500" placeholder="MM/AA">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-300 mb-1">CVV / CVC (3 dígitos)</label>
                                <input type="text" id="inputCvv" value="882" maxlength="4" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm font-mono text-slate-100 focus:outline-none focus:border-blue-500" placeholder="•••">
                            </div>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="space-y-3 pt-2">
                        <button type="button" onclick="submitPayment('approve', 'approved')" class="w-full bg-blue-600 hover:bg-blue-500 text-white font-bold py-3.5 px-4 rounded-xl transition duration-150 flex items-center justify-center gap-3 shadow-lg shadow-blue-900/40 text-base">
                            <i class="fa-solid fa-lock text-sm"></i>
                            <span>Pagar S/ {{ number_format($order->total, 2) }}</span>
                        </button>
                        
                        <div class="grid grid-cols-2 gap-3">
                            <button type="button" onclick="submitPayment('reject', 'insufficient_funds')" class="w-full bg-slate-800 hover:bg-rose-950 text-rose-300 border border-slate-700 hover:border-rose-700 font-semibold py-2.5 px-3 rounded-xl transition text-xs flex items-center justify-center gap-2">
                                <i class="fa-solid fa-triangle-exclamation"></i>
                                <span>Simular Rechazo</span>
                            </button>
                            <a href="{{ $cancelUrl }}" class="w-full bg-slate-800 hover:bg-slate-700 text-slate-300 border border-slate-700 font-semibold py-2.5 px-3 rounded-xl transition text-xs flex items-center justify-center gap-2 text-center">
                                <i class="fa-solid fa-xmark"></i>
                                <span>Cancelar y volver</span>
                            </a>
                        </div>
                    </div>
                </form>

                <p class="text-[11px] text-slate-400 text-center flex items-center justify-center gap-1.5">
                    <i class="fa-solid fa-shield-halved text-slate-400"></i>
                    <span>Modo demostración. Ningún dato real de tarjeta es solicitado ni guardado en la plataforma.</span>
                </p>
            </div>

            <!-- Right Column: Order Summary -->
            <div class="lg:col-span-5 bg-slate-800/70 border border-slate-700/80 rounded-2xl p-5 sm:p-6 space-y-5">
                <h2 class="text-base font-bold text-slate-100 flex items-center justify-between border-b border-slate-700/80 pb-3">
                    <span>Resumen de tu pedido</span>
                    <span class="text-xs font-normal text-slate-400">Pedido #{{ $order->id }}</span>
                </h2>

                <!-- Items list -->
                <div class="max-h-48 overflow-y-auto space-y-3 pr-1 text-sm">
                    @foreach($order->items as $item)
                        <div class="flex justify-between items-center gap-3">
                            <div class="truncate">
                                <span class="font-medium text-slate-200 block truncate">{{ $item->product->name ?? 'Producto' }}</span>
                                <span class="text-xs text-slate-400">Cant: {{ $item->quantity }} × S/ {{ number_format($item->price, 2) }}</span>
                            </div>
                            <span class="font-mono font-semibold text-slate-200 shrink-0">S/ {{ number_format($item->subtotal, 2) }}</span>
                        </div>
                    @endforeach
                </div>

                <!-- Totals -->
                <div class="border-t border-slate-700/80 pt-4 space-y-2 text-sm">
                    <div class="flex justify-between text-slate-400">
                        <span>Subtotal de productos</span>
                        <span class="font-mono text-slate-200">S/ {{ number_format($order->subtotal ?? $order->total, 2) }}</span>
                    </div>
                    <div class="flex justify-between text-slate-400">
                        <span>Costo de entrega ({{ $order->delivery_type === 'pickup' ? 'Recojo' : 'Domicilio' }})</span>
                        <span class="font-mono text-slate-200">S/ {{ number_format($order->shipping_amount ?? 0, 2) }}</span>
                    </div>
                    <div class="flex justify-between text-base font-bold text-white pt-2 border-t border-slate-700/60">
                        <span>Total a pagar</span>
                        <span class="font-mono text-emerald-400 text-lg">S/ {{ number_format($order->total, 2) }}</span>
                    </div>
                </div>

                <!-- Client Details -->
                <div class="bg-slate-900/60 border border-slate-700/50 rounded-xl p-3.5 text-xs text-slate-400 space-y-1.5">
                    <div class="flex justify-between">
                        <span class="text-slate-400">Cliente:</span>
                        <span class="text-slate-200 font-medium">{{ $order->user->name ?? 'Cliente' }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-slate-400">Correo:</span>
                        <span class="text-slate-200 font-medium truncate max-w-[180px]">{{ $order->user->email ?? 'correo@ejemplo.com' }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-slate-400">Modalidad:</span>
                        <span class="text-blue-400 font-medium">{{ $order->delivery_type === 'pickup' ? 'Recojo en sede' : 'Envío a domicilio' }}</span>
                    </div>
                </div>
            </div>

        </div>
    </main>

    <!-- Footer -->
    <footer class="border-t border-slate-800/80 bg-slate-950 py-4 px-6 text-center text-xs text-slate-400">
        <p>Sistema de Comercio y Distribución de Lubricantes · Simulador de Pagos v1.0</p>
    </footer>

    <script>
        function setScenario(type) {
            const cardEl = document.getElementById('cardElement');
            const logoEl = document.getElementById('cardBrandLogo');
            const numEl = document.getElementById('cardDisplayNumber');
            const nameEl = document.getElementById('cardDisplayName');
            const expEl = document.getElementById('cardDisplayExpiry');
            const inputName = document.getElementById('inputCardName');
            const inputExp = document.getElementById('inputExpiry');

            if (type === 'visa_approved') {
                cardEl.className = cardEl.className.replace('card-gradient-mc', 'card-gradient-visa');
                logoEl.innerHTML = '<span class="font-black italic text-2xl tracking-wider text-white">VISA</span>';
                numEl.textContent = '4557 •••• •••• 8820';
                nameEl.textContent = 'JUAN PEREZ';
                expEl.textContent = '12/28';
                inputName.value = 'JUAN PEREZ';
                inputExp.value = '12/28';
                document.getElementById('selectedScenario').value = 'approved';
                document.getElementById('selectedAction').value = 'approve';
            } else if (type === 'mastercard_approved') {
                cardEl.className = cardEl.className.replace('card-gradient-visa', 'card-gradient-mc');
                logoEl.innerHTML = '<span class="font-bold text-xl text-orange-400">mastercard</span>';
                numEl.textContent = '5412 •••• •••• 4401';
                nameEl.textContent = 'MARIA RODRIGUEZ';
                expEl.textContent = '08/27';
                inputName.value = 'MARIA RODRIGUEZ';
                inputExp.value = '08/27';
                document.getElementById('selectedScenario').value = 'approved';
                document.getElementById('selectedAction').value = 'approve';
            } else if (type === 'insufficient_funds') {
                numEl.textContent = '4000 •••• •••• 0002';
                document.getElementById('selectedScenario').value = 'insufficient_funds';
                document.getElementById('selectedAction').value = 'reject';
            } else if (type === 'declined') {
                numEl.textContent = '4000 •••• •••• 0004';
                document.getElementById('selectedScenario').value = 'declined';
                document.getElementById('selectedAction').value = 'reject';
            }
        }

        function updateVisualCard() {
            const inputName = document.getElementById('inputCardName').value.trim();
            const inputExp = document.getElementById('inputExpiry').value.trim();
            if (inputName) document.getElementById('cardDisplayName').textContent = inputName.toUpperCase();
            if (inputExp) document.getElementById('cardDisplayExpiry').textContent = inputExp;
        }

        function submitPayment(action, scenario) {
            document.getElementById('selectedAction').value = action;
            document.getElementById('selectedScenario').value = scenario;
            document.getElementById('paymentForm').submit();
        }
    </script>
</body>
</html>
