<?php
    $title = "Mi Perfil";
    $section = "Mi Perfil";

    require_once __DIR__.'/../layout/title.php';
?>

                <div class="container-fluid">
                    <div class="mb-5">
                        <div class="card !rounded-none">
                            <div class="card-body !px-2.5">
                                <div class="grid lg:grid-cols-12 grid-cols-1 gap-5">
                                    <div class="col-span-1">

                                        <div class="relative inline-block rounded-full shadow-md size-20 profile-user xl:size-28">
                                            <img src="<?= asset('/images/logos/logo_fold.png'); ?>" alt="" class="object-cover border-0 rounded-full img-thumbnail user-profile-image">
                                        </div>
                                    </div>

                                    <div class="lg:col-span-9 col-span-1">
                                        <h5 id="field-profile-name" class="mb-1 flex items-center gap-1">...</h5>

                                        <div class="flex gap-3 mb-4">
                                            <p class="text-default-500 flex gap-1 items-center">
                                                <i data-lucide="user-circle" class="fill-default-100 size-4"></i>
                                                <span id="field-profile-type">...</span>
                                            </p>

                                            <p class="text-default-500 flex gap-1 items-center">
                                                <i data-lucide="mail" class="size-4"></i>
                                                <span id="field-profile-email">email</span>
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="!px-2.5">
                                <div>
                                    <nav class="flex gap-1 flex-wrap" aria-label="Tabs" role="tablist" aria-orientation="horizontal">
                                        <button type="button"
                                            class="hs-tab-active:font-semibold hs-tab-active:border-primary hs-tab-active:text-primary py-2 px-4 inline-flex items-center gap-2 border-b border-transparent text-sm whitespace-nowrap text-default-500 hover:text-primary focus:outline-hidden focus:text-primary disabled:opacity-50 disabled:pointer-events-none active"
                                            id="changePasswordTab"
                                            aria-selected="true"
                                            data-hs-tab="#changePassword"
                                            aria-controls="changePassword"
                                            role="tab">
                                            Modificar Contraseña
                                        </button>
                                    </nav>
                                </div>

                            </div>
                        </div>
                    </div>

                    <div id="changePassword" role="tabpanel" aria-labelledby="changePasswordTab">
                        <div class="card">
                            <div class="card-body">
                                <h6 class="mb-4 text-[15px]">Modificar Contraseña</h6>

                                <form id="form-change-password" no-validate action="javascript:ChangePassword()">
                                    <div class="grid grid-cols-1 gap-5 lg:grid-cols-12">
                                        <div class="lg:col-span-4 col-span-1">
                                            <label for="field-old-password" class="inline-block mb-2 font-medium">Contraseña Actual*</label>

                                            <div class="relative">
                                                <input id="field-old-password"
                                                    type="password"
                                                    class="form-input disabled:opacity-50 disabled:pointer-events-none"
                                                    placeholder="Captura tu contraseña actual"
                                                    required>
                                                <button type="button"
                                                    data-hs-toggle-password='{"target": "#field-old-password" }'
                                                    class="absolute inset-y-0 end-0 flex items-center z-20 px-3 cursor-pointer"
                                                    tabindex="-1">
                                                    <i data-lucide="eye-off" class="hs-password-active:block hidden size-3.5"></i>
                                                    <i data-lucide="eye" class="hs-password-active:hidden block size-3.5"></i>
                                                </button>
                                            </div>
                                        </div>

                                        <div class="lg:col-span-4 col-span-1">
                                            <label for="field-new-password" class="inline-block mb-2 font-medium">Nueva Contraseña*</label>

                                            <div class="relative">
                                                <input id="field-new-password"
                                                    type="password"
                                                    class="form-input disabled:opacity-50 disabled:pointer-events-none"
                                                    placeholder="Captura tu nueva contraseña"
                                                    required>
                                                <button type="button"
                                                    data-hs-toggle-password='{"target": "#field-new-password" }'
                                                    class="absolute inset-y-0 end-0 flex items-center z-20 px-3 cursor-pointer"
                                                    tabindex="-1">
                                                    <i data-lucide="eye-off" class="hs-password-active:block hidden size-3.5"></i>
                                                    <i data-lucide="eye" class="hs-password-active:hidden block size-3.5"></i>
                                                </button>
                                            </div>
                                        </div>

                                        <div class="lg:col-span-4 col-span-1">
                                            <label for="field-confirm-password" class="inline-block mb-2 font-medium">Confirmar Contraseña*</label>

                                            <div class="relative">
                                                <input id="field-confirm-password"
                                                    type="password"
                                                    class="form-input disabled:opacity-50 disabled:pointer-events-none"
                                                    placeholder="Confirma tu nueva contraseña"
                                                    required>
                                                <button type="button"
                                                    data-hs-toggle-password='{"target": "#field-confirm-password" }'
                                                    class="absolute inset-y-0 end-0 flex items-center z-20 px-3 cursor-pointer"
                                                    tabindex="-1">
                                                    <i data-lucide="eye-off" class="hs-password-active:block hidden size-3.5"></i>
                                                    <i data-lucide="eye" class="hs-password-active:hidden block size-3.5"></i>
                                                </button>
                                            </div>
                                        </div>

                                        <div class="flex justify-end lg:col-span-12 col-span-1">
                                            <button type="submit" class="btn bg-primary text-white">Modificar Contraseña</button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

<script src="<?= asset('js/profile/index.js'); ?>"></script>

<script>
    var currentLink = '<?= base_url('profile'); ?>';
    var user_id = '<?= $id; ?>';
</script>