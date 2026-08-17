<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <meta http-equiv="X-UA-Compatible" content="ie=edge">
   <link rel="shortcut icon" type="image/x-icon" href="<?= asset('images/favicon/favicon.ico'); ?>" />
   <link rel="icon" type="image/png" sizes="16x16" href="<?= asset('images/favicon/favicon-16x16.png'); ?>" />
   <link rel="icon" type="image/png" sizes="32x32" href="<?= asset('images/favicon/favicon-32x32.png'); ?>" />
   <link rel="icon" type="image/png" sizes="96x96" href="<?= asset('images/favicon/favicon-96x96.png'); ?>" />

   <link rel="apple-touch-icon" sizes="57x57" href="<?= asset('images/favicon/apple-57x57-touch-icon.png'); ?>" />
   <link rel="apple-touch-icon" sizes="60x60" href="<?= asset('images/favicon/apple-60x60-touch-icon.png'); ?>" />
   <link rel="apple-touch-icon" sizes="72x72" href="<?= asset('images/favicon/apple-72x72-touch-icon.png'); ?>" />
   <link rel="apple-touch-icon" sizes="76x76" href="<?= asset('images/favicon/apple-76x76-touch-icon.png'); ?>" />
   <link rel="apple-touch-icon" sizes="114x114" href="<?= asset('images/favicon/apple-114x114-touch-icon.png'); ?>" />
   <link rel="apple-touch-icon" sizes="120x120" href="<?= asset('images/favicon/apple-120x120-touch-icon.png'); ?>" />
   <link rel="apple-touch-icon" sizes="144x144" href="<?= asset('images/favicon/apple-144x144-touch-icon.png'); ?>" />
   <link rel="apple-touch-icon" sizes="152x152" href="<?= asset('images/favicon/apple-152x152-touch-icon.png'); ?>" />
   <link rel="apple-touch-icon" sizes="180x180" href="<?= asset('images/favicon/apple-180x180-touch-icon.png'); ?>" />
   <link rel="icon" type="image/png" sizes="196x196" href="<?= asset('images/favicon/android-chrome-196x196.png'); ?>" />
   <link rel="icon" type="image/png" href="<?= asset('images/favicon/android-chrome-192x192.png'); ?>" sizes="192x192" type="image/png">
   <link rel="icon" type="image/png" href="<?= asset('images/favicon/android-chrome-256x256.png'); ?>" sizes="256x256" type="image/png">
   <link rel="icon" type="image/png" href="<?= asset('images/favicon/android-chrome-384x384.png'); ?>" sizes="384x384" type="image/png">
   <link rel="icon" type="image/png" href="<?= asset('images/favicon/android-chrome-512x512.png'); ?>" sizes="512x512" type="image/png">

   <link rel="manifest" href="/manifest.webmanifest">

   <!-- <meta name="theme-color" content="#ffffff"> -->
   <meta name="theme-color" content="#0099cc" />

   <meta name="apple-mobile-web-app-capable" content="yes">
   <meta name="apple-mobile-web-app-status-bar-style" content="default">
   <meta name="apple-mobile-web-app-title" content="<?= config('name'); ?>">

   <link rel="apple-touch-icon" href="/assets/pwa/icon-180.png">
   
   <meta name="msapplication-TileColor" content="#0099cc" />
   <meta name="msapplication-TileImage" content="<?= asset('images/favicon/windows-tile.png'); ?>">
   <meta name="msapplication-square70x70logo" content="<?= asset('images/favicon/windows-small-tile.png'); ?>" />
   <meta name="msapplication-square150x150logo" content="<?= asset('images/favicon/windows-medium-tile.png'); ?>" />
   <meta name="msapplication-wide310x150logo" content="<?= asset('images/favicon/windows-wide-tile.png'); ?>" />
   <meta name="msapplication-square310x310logo" content="<?= asset('images/favicon/windows-large-tile.png'); ?>" />
   <title><?= config('name'); ?></title>

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
    <link rel="modulepreload" crossorigin href="<?= base_url('template/assets/app-BxTRRtUp.js'); ?>">
    <script type="module" crossorigin src="<?= base_url('template/assets/index-DsDs3XAz.js'); ?>"></script>
    <link rel="modulepreload" crossorigin href="<?= base_url('template/assets/flatpickr-DxeCcIwz.js'); ?>">
    <link rel="modulepreload" crossorigin href="<?= base_url('template/assets/apexcharts.esm-DPbJ6jlt.js'); ?>">
    <!-- <script type="module" crossorigin src="<?= base_url('/template/assets/form-choices-CeuTJViI.js'); ?>"></script> -->
    <link rel="stylesheet" crossorigin href="<?= base_url('template/assets/app-0ZOPNGSF.css'); ?>">
    <link rel="stylesheet" crossorigin href="<?= base_url('/template/assets/app-tw.css'); ?>">
    <script src="<?= asset('js/global.js') ?>"></script>
</head>

<body>

    <div class="wrapper">

        <aside id="app-menu" class="app-menu">
        
            <a href="<?= base_url(''); ?>" class="logo-box sticky top-0 flex min-h-topbar-height items-center justify-start px-6 backdrop-blur-xs">
                <div class="logo-light">
                    <img src="<?= asset('/images/logos/logo_h.png'); ?>" class="logo-lg w-[180px]" alt="Logo Sicrat">
                    <img src="data:image/png;data:image/png;base64,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" class="logo-sm h-6" alt="Sicrat logo">
                </div>
        
                <div class="logo-dark">
                    <img src="<?= asset('/images/logos/logo_h.png'); ?>" class="logo-lg w-[180px]" alt="Logo Sicrat D">
                    <img src="data:image/png;data:image/png;base64,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" class="logo-sm h-6" alt="Sicrat logo">
                </div>
            </a>
        
            <div class="absolute top-0 end-5 flex h-topbar items-center justify">
                <button id="button-hover-toggle">
                    <i class="iconify tabler--circle size-5"></i>
                </button>
            </div>
        
             <?php require __DIR__ . "/sidebar-menu.php"; ?>
        
        </aside>
        <div class="page-content">

            <div class="app-header min-h-topbar-height flex items-center sticky top-0 z-30 bg-(--topbar-background) border-b border-default-200">
                <div class="w-full flex items-center justify-between px-6">
                    <div class="flex items-center gap-5">
                        <button id="button-toggle-menu" class="btn btn-icon size-9 bg-default-400/10 hover:bg-default-150 rounded">
                            <i class="iconify lucide--align-left text-xl"></i>
                        </button>
                    </div>
            
                    <div class="flex items-center gap-3">
            
                        <div class="topbar-item">
                            <button class="btn btn-icon size-8 hover:bg-default-150 transition-[scale] rounded-full" id="light-dark-mode" type="button">
                                <i class="iconify tabler--moon text-xl absolute dark:scale-0 dark:-rotate-90 scale-100 rotate-0 transition-all duration-200"></i>
                                <i class="iconify tabler--sun text-xl absolute dark:scale-100 dark:rotate-0 scale-0 rotate-90 transition-all duration-200"></i>
                            </button>
                        </div>
            
                        <div class="topbar-item hs-dropdown relative inline-flex">
                            <button class="cursor-pointer bg-white-100 rounded-full" aria-haspopup="menu" aria-expanded="false" aria-label="Dropdown">
                                <img src="<?= asset('/images/logos/logo_fold.png'); ?>" alt="user-image" class="hs-dropdown-toggle rounded-full size-9.5">
                            </button>
            
                            <div class="hs-dropdown-menu min-w-48" role="menu" aria-orientation="vertical" aria-labelledby="hs-dropdown-with-icons">
                                <div class="p-2">
                                    <h6 class="mb-2 text-default-500">Bienvenido a SICRAT</h6>
            
                                    <a href="<?= base_url('/profile/'.$_SESSION['SICRAT_UUID']); ?>" class="flex gap-3">
                                        <div class="relative inline-block">
                                            <div class="rounded bg-white-200">
                                                <img src="<?= asset('/images/logos/logo_fold.png'); ?>" alt="" class="size-12 rounded">
                                            </div>
                                            <span class="-top-1 -end-1 absolute size-2.5 bg-green-400 border-2 border-white rounded-full"></span>
                                        </div>
            
                                        <div>
                                            <h6 class="mb-1 text-sm font-semibold text-default-800"><?= $_SESSION['SICRAT_NAME']; ?></h6>
                                            <p class="text-default-500"><?= $_SESSION['SICRAT_USER_TYPE']; ?></p>
                                        </div>
                                    </a>
                                </div>
            
                                <div class="border-t border-t-default-200 -mx-2 my-2"></div>
            
                                <div class="flex flex-col gap-y-1">
            
                                    <a class="flex items-center gap-x-3.5 py-1.5 font-medium px-3 text-default-600 hover:bg-default-150 rounded" href="javascript:CerrarSesion();">
                                        <i data-lucide="log-out" class="size-4"></i>
                                        Salir
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <main>