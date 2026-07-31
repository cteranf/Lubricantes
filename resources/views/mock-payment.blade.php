<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Simulador de Pago - Mock Gateway</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-100">
    <div class="min-h-screen flex items-center justify-center p-4">
        <div class="bg-white rounded-lg shadow-xl p-8 max-w-md w-full">
            <div class="text-center mb-6">
                <div class="bg-blue-100 rounded-full w-16 h-16 flex items-center justify-center mx-auto mb-4">
                    <svg class="w-8 h-8 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z">
                        </path>
                    </svg>
                </div>
                <h1 class="text-2xl font-bold text-gray-800">Simulador de Pago</h1>
                <p class="text-gray-600 mt-2">Pasarela de Pago Mock (Desarrollo)</p>
            </div>

            <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 mb-6">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <svg class="h-5 w-5 text-yellow-400" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd"
                                d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z"
                                clip-rule="evenodd" />
                        </svg>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm text-yellow-700">
                            <strong>Modo de Prueba:</strong> Esta es una pasarela simulada para desarrollo. No se
                            procesarán pagos reales.
                        </p>
                    </div>
                </div>
            </div>

            <div class="space-y-4 mb-6">
                <div class="flex justify-between py-2 border-b">
                    <span class="text-gray-600">ID de Pago:</span>
                    <span class="font-mono text-sm">{{ $paymentId }}</span>
                </div>
                <div class="flex justify-between py-2 border-b">
                    <span class="text-gray-600">ID de Orden:</span>
                    <span class="font-mono text-sm">#{{ $orderId }}</span>
                </div>
            </div>

            <form action="{{ $approveUrl }}" method="POST">
                @csrf

                <button type="submit"
                    class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-3 px-4 rounded-lg transition duration-200 flex items-center justify-center">
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                    </svg>
                    Aprobar Pago (Simulado)
                </button>
            </form>

            <p class="text-center text-sm text-gray-500 mt-4">
                Al hacer clic, se simulará un pago exitoso y se actualizará tu orden automáticamente.
            </p>
        </div>
    </div>
</body>

</html>
