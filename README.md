# Lubricantes Store E-commerce

Sistema de tienda online de lubricantes desarrollado con Laravel 9 y Vue 3.

## Requisitos

- PHP 8.0+
- Composer
- Node.js & NPM
- MySQL

## Instalación

1. Clonar repositorio o extraer archivos.
2. Instalar dependencias de PHP:
   ```bash
   composer install
   ```
3. Instalar dependencias de JS:
   ```bash
   npm install
   ```
4. Configurar `.env` con tus credenciales de base de datos.
   ```
   DB_DATABASE=lubricantes
   DB_USERNAME=root
   ```
5. Generar key y migrar:
   ```bash
   php artisan key:generate
   php artisan migrate --seed
   ```

## Ejecución

1. Compilar assets (producción):
   ```bash
   npm run build
   ```
   O para desarrollo:
   ```bash
   npm run dev
   ```
2. Servir la aplicación:
   ```bash
   php artisan serve
   ```
   Acceder a `http://127.0.0.1:8000`.

## Credenciales Admin

- **Email:** admin@lubristore.com
- **Password:** password

## Stack Tecnológico

- **Backend:** Laravel 9 (API REST), Sanctum Auth.
- **Frontend:** Vue 3, Vite, Pinia, Vue Router.
- **UI:** TailwindCSS, PrimeVue.
- **DB:** MySQL.
