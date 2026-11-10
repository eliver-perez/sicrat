<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="csrf-token" content="<?= escape_html(\App\Core\Security\Csrf::token()); ?>">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <meta http-equiv="X-UA-Compatible" content="ie=edge">
   <link rel="shortcut icon" type="image/x-icon" href="<?= escape_html(asset('images/favicon/favicon.ico')); ?>" />
   <link rel="icon" type="image/png" sizes="16x16" href="<?= escape_html(asset('images/favicon/favicon-16x16.png')); ?>" />
   <link rel="icon" type="image/png" sizes="32x32" href="<?= escape_html(asset('images/favicon/favicon-32x32.png')); ?>" />
   <link rel="icon" type="image/png" sizes="96x96" href="<?= escape_html(asset('images/favicon/favicon-96x96.png')); ?>" />

   <link rel="apple-touch-icon" sizes="57x57" href="<?= escape_html(asset('images/favicon/apple-57x57-touch-icon.png')); ?>" />
   <link rel="apple-touch-icon" sizes="60x60" href="<?= escape_html(asset('images/favicon/apple-60x60-touch-icon.png')); ?>" />
   <link rel="apple-touch-icon" sizes="72x72" href="<?= escape_html(asset('images/favicon/apple-72x72-touch-icon.png')); ?>" />
   <link rel="apple-touch-icon" sizes="76x76" href="<?= escape_html(asset('images/favicon/apple-76x76-touch-icon.png')); ?>" />
   <link rel="apple-touch-icon" sizes="114x114" href="<?= escape_html(asset('images/favicon/apple-114x114-touch-icon.png')); ?>" />
   <link rel="apple-touch-icon" sizes="120x120" href="<?= escape_html(asset('images/favicon/apple-120x120-touch-icon.png')); ?>" />
   <link rel="apple-touch-icon" sizes="144x144" href="<?= escape_html(asset('images/favicon/apple-144x144-touch-icon.png')); ?>" />
   <link rel="apple-touch-icon" sizes="152x152" href="<?= escape_html(asset('images/favicon/apple-152x152-touch-icon.png')); ?>" />
   <link rel="apple-touch-icon" sizes="180x180" href="<?= escape_html(asset('images/favicon/apple-180x180-touch-icon.png')); ?>" />
   <link rel="icon" type="image/png" sizes="196x196" href="<?= escape_html(asset('images/favicon/android-chrome-196x196.png')); ?>" />
   <link rel="icon" type="image/png" href="<?= escape_html(asset('images/favicon/android-chrome-192x192.png')); ?>" sizes="192x192" type="image/png">
   <link rel="icon" type="image/png" href="<?= escape_html(asset('images/favicon/android-chrome-256x256.png')); ?>" sizes="256x256" type="image/png">
   <link rel="icon" type="image/png" href="<?= escape_html(asset('images/favicon/android-chrome-384x384.png')); ?>" sizes="384x384" type="image/png">
   <link rel="icon" type="image/png" href="<?= escape_html(asset('images/favicon/android-chrome-512x512.png')); ?>" sizes="512x512" type="image/png">

   <link rel="manifest" href="/manifest.webmanifest">
   <link rel="stylesheet" href="/assets/css/pwa.css">
   <script src="/assets/js/pwa/register.js" defer></script>

   <!-- <meta name="theme-color" content="#ffffff"> -->
   <meta name="theme-color" content="#0099cc" />

   <meta name="apple-mobile-web-app-capable" content="yes">
   <meta name="apple-mobile-web-app-status-bar-style" content="default">
   <meta name="apple-mobile-web-app-title" content="<?= escape_html(config('name')); ?>">

   <link rel="apple-touch-icon" href="/assets/images/favicon/apple-180x180-touch-icon.png">
   
   <meta name="msapplication-TileColor" content="#0099cc" />
   <meta name="msapplication-TileImage" content="<?= escape_html(asset('images/favicon/windows-tile.png')); ?>">
   <meta name="msapplication-square70x70logo" content="<?= escape_html(asset('images/favicon/windows-small-tile.png')); ?>" />
   <meta name="msapplication-square150x150logo" content="<?= escape_html(asset('images/favicon/windows-medium-tile.png')); ?>" />
   <meta name="msapplication-wide310x150logo" content="<?= escape_html(asset('images/favicon/windows-wide-tile.png')); ?>" />
   <meta name="msapplication-square310x310logo" content="<?= escape_html(asset('images/favicon/windows-large-tile.png')); ?>" />
   <title><?= escape_html(config('name')); ?></title>

    <script>
        (function () {
            const html = document.documentElement;
            const storageKey = "__SICRAT_CONFIG__";
            const savedConfig = sessionStorage.getItem(storageKey);
    
            // Default config
            const defaultConfig = {
                dir: "ltr",
                theme: "light",
                sidenav: {
                    color: "light",
                    size: "default",
                },
            };
    
            // Build config from HTML attributes
            function getSystemTheme() {
                return window.matchMedia('(prefers-color-scheme: dark)').matches ? "dark" : "light";
            }
    
            // Build config from HTML attributes
            const htmlConfig = {
                dir: html.getAttribute("dir") || defaultConfig.dir,
    
                theme: html.getAttribute("data-theme") === 'system'
                    ? getSystemTheme()
                    : html.getAttribute("data-theme") || (defaultConfig.theme === 'system' ? getSystemTheme() : defaultConfig.theme),
                sidenav: {
                    color: html.getAttribute("data-sidenav-color") || defaultConfig.sidenav.color,
                    size: html.getAttribute("data-sidenav-size") || defaultConfig.sidenav.size,
                },
            };
    
            // Save merged config as defaults globally
            window.defaultConfig = structuredClone(htmlConfig);
    
            // Load from session if exists
            let config = savedConfig ? JSON.parse(savedConfig) : htmlConfig;
            window.config = config;
    
            // Apply layout attributes immediately
            html.setAttribute("dir", config.dir);
            html.setAttribute("data-theme", config.theme);
            html.setAttribute("data-sidenav-color", config.sidenav.color);
    
            if (config.sidenav.size) {
                let size = config.sidenav.size;
    
                if (window.innerWidth <= 1140) {
                    size = "offcanvas";
                }
    
                html.setAttribute("data-sidenav-size", size);
            }
        })();
    </script>
    <link rel="modulepreload" crossorigin href="<?= escape_html(base_url('template/assets/app-BxTRRtUp.js')); ?>">
    <script type="module" crossorigin src="<?= escape_html(base_url('template/assets/app-BxTRRtUp.js')); ?>"></script>
    <link rel="modulepreload" crossorigin href="<?= escape_html(base_url('template/assets/flatpickr-DxeCcIwz.js')); ?>">
    <link rel="modulepreload" crossorigin href="<?= escape_html(base_url('template/assets/apexcharts.esm-DPbJ6jlt.js')); ?>">
    <!-- <script type="module" crossorigin src="<?= escape_html(base_url('/template/assets/form-choices-CeuTJViI.js')); ?>"></script> -->
    <link rel="stylesheet" crossorigin href="<?= escape_html(base_url('template/assets/app-0ZOPNGSF.css')); ?>">
    <link rel="stylesheet" crossorigin href="<?= escape_html(base_url('/template/assets/app-tw.css')); ?>">
    <link rel="stylesheet" href="<?= escape_html(asset('css/platform.css')); ?>">
    <script src="<?= escape_html(asset('js/global.js')); ?>"></script>
    <script src="<?= escape_html(asset('js/ux.js')); ?>"></script>
    <script src="<?= escape_html(asset('js/lucide-components.js')); ?>"></script>
<link rel="stylesheet" href="/assets/css/theme.css">
<script src="/assets/js/theme.js"></script>
<script src="/assets/js/session.js"></script>
</head>

<body>

    <div class="wrapper">

        <aside id="app-menu" class="app-menu">
        
            <a href="<?= escape_html(base_url('')); ?>" class="logo-box sicrat-brand sticky top-0 flex min-h-topbar-height items-center justify-start px-6 backdrop-blur-xs">
                <div class="logo-light">
                    <img src="<?= escape_html(asset('/images/logos/logo_h.png')); ?>" class="logo-lg w-[180px]" alt="Logo Sicrat">
                    <img src="/assets/images/logos/logo_fold.png" class="logo-sm h-6" alt="Sicrat logo">
                </div>
        
                <div class="logo-dark">
                    <img src="<?= escape_html(asset('/images/logos/logo_h.png')); ?>" class="logo-lg w-[180px]" alt="SICRAT">
                    <img src="/assets/images/logos/logo_fold.png" class="logo-sm h-6" alt="Sicrat logo">
                </div>
            </a>
        
            <div class="absolute top-0 end-5 flex h-topbar items-center justify">
                <button id="button-hover-toggle" aria-label="Cambiar tamaño del menú" type="button">
                    <i class="iconify tabler--circle size-5"></i>
                </button>
            </div>
        
             <?php require __DIR__ . "/sidebar-menu.php"; ?>
        
        </aside>
        <div class="page-content">

            <div class="app-header min-h-topbar-height flex items-center sticky top-0 z-30 bg-(--topbar-background) border-b border-default-200">
                <div class="w-full flex items-center justify-between px-6">
                    <div class="ux-header-context flex items-center gap-5">
                        <button id="button-toggle-menu" aria-label="Mostrar u ocultar menú" type="button" class="btn btn-icon size-9 bg-default-400/10 hover:bg-default-150 rounded">
                            <i class="iconify lucide--align-left text-xl"></i>
                        </button>
                        <div id="electoral-context" class="ux-election-context">
                            <select id="electoral-context-select" class="form-input" aria-label="Elección activa" aria-describedby="electoral-context-status"><option value="">Selecciona una elección</option></select>
                            <span id="electoral-context-status" class="ux-sr-only" role="status"><?= escape_html($_SESSION['SICRAT_ELECTION_NOTICE'] ?? '') ?></span>
                        </div>
                    </div>
            
                    <div class="ux-header-actions flex items-center gap-3">
            
                        <div class="topbar-item">
                            <button class="btn btn-icon size-8 hover:bg-default-150 transition-[scale] rounded-full" id="light-dark-mode" aria-label="Cambiar tema claro u oscuro" type="button">
                                <i class="iconify tabler--moon text-xl absolute dark:scale-0 dark:-rotate-90 scale-100 rotate-0 transition-all duration-200"></i>
                                <i class="iconify tabler--sun text-xl absolute dark:scale-100 dark:rotate-0 scale-0 rotate-90 transition-all duration-200"></i>
                            </button>
                        </div>
            
                        <div class="topbar-item hs-dropdown relative inline-flex" id="header-notifications">
                            <button id="header-notification-toggle" type="button" class="hs-dropdown-toggle btn btn-icon size-8 hover:bg-default-150 rounded-full relative" aria-label="Notificaciones" aria-haspopup="menu" aria-expanded="false"><i data-lucide="bell-ring" class="size-4"></i><span id="header-notification-count" class="ux-header-count" hidden></span></button>
                            <div class="hs-dropdown-menu ux-notification-dropdown p-0" role="menu"><div class="p-3 border-b border-default-200"><strong>Notificaciones</strong></div><div id="header-notification-list" class="ux-notification-list"><p class="p-3">Cargando notificaciones…</p></div><div class="p-3 border-t border-default-200"><a class="btn" href="<?= escape_html(base_url('/services/?tab=notifications')) ?>">Ver todas</a></div></div>
                        </div>
                        <?php
                        $headerChat=false;
                        if(\App\Core\Auth::processId() && $session->verifyUserRights(['mensajes.ver'])){try{(new \App\Repositories\ModuleRepository((new \App\Core\Database())->getConnection()))->require('mensajeria',\App\Core\Auth::processId());$headerChat=true;}catch(\App\Core\HttpError){}}
                        if($headerChat){ ?><a id="header-chat" class="btn btn-icon size-8 hover:bg-default-150 rounded-full" aria-label="Mensajería" title="Mensajería" href="<?= escape_html(base_url('/operations/?module=conversaciones')) ?>"><i data-lucide="messages-square" class="size-4"></i></a><?php } ?>
                        <div class="topbar-item hs-dropdown relative inline-flex">
                            <button class="cursor-pointer bg-white-100 rounded-full" aria-haspopup="menu" aria-expanded="false" aria-label="Mi cuenta">
                                <img src="<?= escape_html(asset('/images/logos/logo_fold.png')); ?>" alt="SICRAT" class="hs-dropdown-toggle rounded-full size-9.5 sicrat-brand-icon">
                            </button>
            
                            <div class="hs-dropdown-menu min-w-48" role="menu" aria-orientation="vertical" aria-labelledby="hs-dropdown-with-icons">
                                <div class="p-2">
                                    <h6 class="mb-2 text-default-500">Bienvenido a SICRAT</h6>
            
                                    <a href="<?= escape_html(base_url('/profile/'.$_SESSION['SICRAT_UUID'])); ?>" class="flex gap-3">
                                        <div class="relative inline-block">
                                            <div class="rounded bg-white-200">
                                                <img src="<?= escape_html(asset('/images/logos/logo_fold.png')); ?>" alt="" class="size-12 rounded sicrat-brand-icon">
                                            </div>
                                            <span class="-top-1 -end-1 absolute size-2.5 bg-green-400 border-2 border-white rounded-full"></span>
                                        </div>
            
                                        <div>
                                            <h6 class="mb-1 text-sm font-semibold text-default-800"><?= escape_html($_SESSION['SICRAT_NAME']); ?></h6>
                                            <p class="text-default-500"><?= escape_html($_SESSION['SICRAT_USER_TYPE']); ?></p>
                                        </div>
                                    </a>
                                </div>
            
                                <div class="border-t border-t-default-200 -mx-2 my-2"></div>
            
                                <div class="flex flex-col gap-y-1">
                                    <a class="flex items-center gap-x-3 py-1.5 px-3 hover:bg-default-150 rounded" href="<?= escape_html(base_url('/profile/'.$_SESSION['SICRAT_UUID'])) ?>"><i data-lucide="user-round" class="size-4"></i>Mi perfil</a>
                                    <a class="flex items-center gap-x-3 py-1.5 px-3 hover:bg-default-150 rounded" href="<?= escape_html(base_url('/services/?tab=notifications')) ?>"><i data-lucide="bell" class="size-4"></i>Notificaciones</a>
                                    <?php if($headerChat){ ?><a class="flex items-center gap-x-3 py-1.5 px-3 hover:bg-default-150 rounded" href="<?= escape_html(base_url('/operations/?module=conversaciones')) ?>"><i data-lucide="messages-square" class="size-4"></i>Mensajería</a><?php } ?>
                                    <a class="flex items-center gap-x-3 py-1.5 px-3 hover:bg-default-150 rounded" href="<?= escape_html(base_url('/profile/'.$_SESSION['SICRAT_UUID'].'#password')) ?>"><i data-lucide="key-round" class="size-4"></i>Cambiar contraseña</a>
            
                                    <a class="flex items-center gap-x-3.5 py-1.5 font-medium px-3 text-default-600 hover:bg-default-150 rounded" href="javascript:CerrarSesion();">
                                        <i data-lucide="log-out" class="size-4"></i>
                                        Cerrar sesión
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <main>
