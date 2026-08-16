                </main>

                <!-- Footer Start -->
                <footer class="mt-auto footer flex items-center py-5 border-t border-default-200">
                    <div class="lg:px-8 px-6 w-full flex md:justify-between justify-center gap-4">
                        <div>
                            <script>document.write(new Date().getFullYear())</script> © SICRAT
                        </div>
                        <div class="md:flex hidden gap-2 item-center md:justify-end">
                        <a href="#" target="_blank" class="text-primary">Sistema de Control de Red y Administración Territorial</a>
                        </div>
                    </div>
                </footer>
                <!-- Footer End -->
            </div>
            <!-- End Page content -->
        </div>

    </body>
    <script src="<?= base_url('template/'); ?>assets/js/sweetalert2.all.min.js"></script>
    <script src="<?= base_url('template/'); ?>assets/js/sweetalert.init.js"></script>
    <script src="<?= base_url('template/'); ?>assets/js/jquery-3.7.1.min.js"></script>
    <script src="<?= asset('js/sweetalert.js') ?>"></script>
    <script src="<?= asset('js/sha512.js') ?>"></script>
    <script src="<?= asset('js/forms.js') ?>"></script>

    <script>
        $(document).ready(function (e) {
            InitializeValues('<?= base_url(''); ?>');

            if(currentLink != undefined && currentLink != '')
                $(`a.menu-link[href="${currentLink}"]`).addClass('active');
        });
    </script>

</html>