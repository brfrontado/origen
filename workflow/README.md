<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://travis-ci.org/laravel/framework"><img src="https://travis-ci.org/laravel/framework.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

# Laravel Global Core 2.0

Laravel Global Core es un proyecto generado para funcionar como core en diversos sistemas.

## Instalación

Para utilizar Laravel Global Core es necesario agregar el proyecto como submódulo en una carpeta llamada "laravel-global-core". Posteriormente, abrir el folder laravel-global-core/_COPY_TO_ROOT. 

Deberá copiar todos los archivos en dicha carpeta hacia la raíz de su proyecto. 

**Es necesario crear una base de datos y configurarla en el archivo .env**

Luego, ejecutar el comando

```cmd
composer install
php artisan migrate
cd frontend && npm install
```

Correr en DB el primer usuario

INSERT INTO `sso-elroble`.users (id, name, email, email_verified_at, password, remember_token, created_at, updated_at) VALUES (1, 'Plan B Técnico', 'info@abkbusiness.com', '2022-12-01 07:56:54', '$2y$10$L0mtZqmhsULBRBFfhF.dH.8kXjB0cuFddS.E/qknF10bXW7a3VXfq', null, '2022-12-01 13:55:08', '2023-03-22 20:44:41');


## Cache local

```cmd
php artisan cache:clear && php artisan route:clear && php artisan config:clear && php artisan view:clear
```

## PUSH a servidor (ejemplo)

```cmd
cd /var/www/html/unfolder && git reset --hard origin/master && git pull && cd /var/www/html && sudo chown -R ubuntu:www-data unfolder && sudo chmod 775 unfolder -R && cd /var/www/html/unfolder && php artisan route:cache
```

# Frontend

El template se basa en:

https://coreui.io/demos/vue/4.4/light-v3/#/icons/coreui-icons

## Comandos para frontend

```cmd
# DEVELOPER (watch), contiene hot reloads.
# Esto se puede ver localmente en http://localhost:8080/
npm run watch

# PRODUCTION
npm run build
```

## Menú

El menú de la aplicación se encuentra en 

**/frontend/_nav.js**

Para ver ejemplos de un menú completo, podemos verlos en

**/frontend/_nav.example.js**

## Componentes

### Tablas

https://hc200ok.github.io/vue3-easy-data-table-doc/getting-started.html
