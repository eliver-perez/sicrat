<!doctype html>
    <html lang="en" dir="ltr">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta http-equiv="X-UA-Compatible" content="ie=edge">
        <!-- Meta Tags -->
        <meta name="description" content="Sistema de Autenticacioon">

        <!-- Title -->
        <title><?= config('name'); ?> - Autenticaci&oacute;n</title>
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
        

        
    <script type="module" crossorigin src="<?= base_url('/template/assets/app-BxTRRtUp.js'); ?>"></script>
    <link rel="stylesheet" crossorigin href="<?= base_url('/template/assets/app-0ZOPNGSF.css'); ?>">
    <link rel="stylesheet" crossorigin href="<?= base_url('/template/assets/app-tw.css'); ?>">

    </head>

    <body>

        <div class="relative min-h-screen w-full flex justify-center items-center py-16 md:py-10">
            <div class="card md:w-lg w-screen z-10">
                <div class="text-center px-10 py-12">
                    <!-- Logo -->
                    <div class="flex justify-center">
                        <img src="<?= asset('/logos/logo_v.png'); ?>" alt="logo dark" class="w-[200px] flex dark:hidden">
                        <img src="<?= asset('/logos/logo_v.png'); ?>" alt="logo light" class="w-[200px] hidden dark:flex" alt="">
                    </div>

                    <form id="form-authenticate" action="javascript:Authenticate()" class="text-left w-full mt-10">
                        <div class="mb-4">
                            <label for="field-username" class="block font-medium text-default-900 text-sm mb-2">Usuario</label>
                            <input type="text" id="field-username" class="form-input" placeholder="Captura tu Usuario" />
                        </div>

                        <div class="mb-4">
                            <!-- <a href="#" class="text-primary font-medium text-sm mb-2 float-end">¿Olvidaste tu Contraseña?</a> -->
                            <label for="field-password" class="block font-medium text-default-900 text-sm mb-2">Contraseña</label>
                            <input type="password" id="field-password" class="form-input" placeholder="Captura tu Contraseña" />
                        </div>

                        <div class="flex items-center gap-2 mb-4">
                            <input id="checkbox-1" type="checkbox" class="form-checkbox">
                            <label class="text-default-900 text-sm font-medium" for="checkbox-1">Recordarme</label>
                        </div>

                        <div class="mt-10 text-center">
                            <button type="submit" class="btn bg-primary text-white w-full">Ingresar<button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="absolute inset-0 overflow-hidden">
                <svg aria-hidden="true" class="absolute inset-0 size-full fill-black/2 stroke-black/5 dark:fill-white/2.5 dark:stroke-white/2.5">
                    <defs>
                        <pattern id="authPattern" width="56" height="56" patternUnits="userSpaceOnUse" x="50%" y="16">
                            <path d="M.5 56V.5H72" fill="none"></path>
                        </pattern>
                    </defs>
                    <rect width="100%" height="100%" stroke-width="0" fill="url(#authPattern)"></rect>
                </svg>
            </div>
        </div>

    </body>
    <script src="<?= base_url('template/'); ?>assets/js/sweetalert2.all.min.js"></script>
    <script src="<?= base_url('template/'); ?>assets/js/sweetalert.init.js"></script>
    <script src="<?= base_url('template/'); ?>assets/js/jquery-3.7.1.min.js"></script>
    <script src="<?= asset('js/sweetalert.js') ?>"></script>
    <script src="<?= asset('js/sha512.js') ?>"></script>
    <script src="<?= asset('js/forms.js') ?>"></script>
    <script src="<?= asset('js/autenticar.js') ?>"></script>

    <script>
        $(document).ready(function (e) {
            InitializeValues('<?= base_url(''); ?>');
        });
    </script>

</html>