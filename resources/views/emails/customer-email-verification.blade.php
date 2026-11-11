<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verifica tu correo electrónico</title>
</head>
<body style="margin:0; padding:0; background-color:#f3f4f6; color:#1f2937; font-family:Arial,Helvetica,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%; border-collapse:collapse; background-color:#f3f4f6;">
        <tr>
            <td align="center" style="padding:32px 16px;">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%; max-width:600px; border-collapse:separate; border-spacing:0; background-color:#ffffff; border-radius:12px;">
                    <tr>
                        <td style="padding:24px 32px; background-color:#123b63; border-radius:12px 12px 0 0; color:#ffffff; font-size:22px; font-weight:700;">
                            LubriStore
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px; font-size:16px; line-height:1.6;">
                            <h1 style="margin:0 0 20px; color:#123b63; font-size:24px; line-height:1.3;">Verifica tu correo electrónico</h1>
                            <p style="margin:0 0 16px;">Hola, {{ $customerName }}</p>
                            <p style="margin:0 0 24px;">Confirma tu correo para activar tu acceso como cliente.</p>
                            <p style="margin:0 0 24px; text-align:center;">
                                <a href="{{ $verificationUrl }}" style="display:inline-block; padding:14px 24px; border-radius:6px; background-color:#1769aa; color:#ffffff; font-weight:700; text-decoration:none;">Confirmar correo</a>
                            </p>
                            <p style="margin:0 0 20px;">Este enlace vence en <strong>{{ $expirationMinutes }} minutos</strong>.</p>
                            <p style="margin:0 0 8px;">Si el botón no funciona, copia y pega esta dirección en tu navegador:</p>
                            <p style="margin:0; overflow-wrap:anywhere; word-break:break-word; color:#123b63; font-size:13px;">{{ $verificationUrl }}</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:20px 32px; border-top:1px solid #e5e7eb; color:#4b5563; font-size:14px;">
                            Saludos, equipo de LubriStore
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
