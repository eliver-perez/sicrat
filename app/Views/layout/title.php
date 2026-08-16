            <div class="flex items-center md:justify-between flex-wrap gap-2 mb-4 print:hidden">
                  <h4 class="text-default-900 text-lg font-semibold"><?php echo isset($title) ? $title : config('name'); ?></h4>
               
                  <div class="md:flex hidden items-center gap-2 text-sm font-semibold">
                     <a href="#" class="text-sm font-medium text-default-700">Dashboard</a>
               
                     <?php
                     if(isset($section) && $section != '') { ?>
                        <i class="iconify tabler--chevron-right text-sm flex-shrink-0 text-default-500 rtl:rotate-180"></i>
                  
                        <a href="#" class="text-sm font-medium text-default-700"><?php echo $section; ?></a>
                           <?php
                     } ?>

                     <?php
                        if(isset($subsection) && $subsection != '') { ?>
                        <i class="iconify tabler--chevron-right text-sm flex-shrink-0 text-default-500 rtl:rotate-180"></i>
                  
                        <a href="#" class="text-sm font-medium text-default-700" aria-current="page"><?php echo $subsection; ?></a>
                           <?php
                     } ?>
                  </div>
               </div>