<?php
// Servidores exclusivos de pruebas: no lee/escribe la BD nativa ni recarga servicios instalados.
function startMessagingNginx(string $directory,string $address,array $environment): array {
    if(!preg_match('/^sicrat_phase1_test_[a-f0-9]{12}$/',basename($directory)) || ($environment['DB_DATABASE'] ?? '')!==basename($directory))throw new RuntimeException('Nginx de pruebas requiere base desechable.');
    $nginx='/opt/homebrew/bin/nginx';$fpm='/opt/homebrew/sbin/php-fpm';
    if(!is_executable($nginx) || !is_executable($fpm))throw new RuntimeException('Nginx/PHP-FPM locales no disponibles; no se instalan dependencias.');
    $public=dirname(__DIR__).'/public';$socket=$directory.'/fpm.sock';
    file_put_contents($directory.'/fpm.conf',"[global]\ndaemonize = no\nerror_log = $directory/fpm-error.log\npid = $directory/fpm.pid\n[test]\nlisten = $socket\npm = static\npm.max_children = 2\nclear_env = no\ncatch_workers_output = yes\nphp_admin_value[session.save_path] = $directory\n");
    file_put_contents($directory.'/nginx.conf',"worker_processes 1;\npid $directory/nginx.pid;\nerror_log $directory/nginx-error.log;\nevents {worker_connections 256;}\nhttp {\ninclude /opt/homebrew/etc/nginx/mime.types;\naccess_log $directory/nginx-access.log;\nclient_body_temp_path $directory/client;\nfastcgi_temp_path $directory/fastcgi;\nclient_max_body_size 24m;\nserver {\nlisten $address;\nserver_name localhost;\nroot $public;\nindex index.php;\nlocation / {try_files \$uri \$uri/ @sicrat;}\nlocation @sicrat {\nrewrite ^/api(?:/.*)?\$ /api.php last;\nrewrite ^/media/([^/]+)\$ /media/index.php?uuid=\$1 last;\nrewrite ^/([^/]+)(?:/.*)?\$ /\$1/index.php last;\nreturn 404;\n}\nlocation ~ \\.php\$ {try_files \$uri =404;include /opt/homebrew/etc/nginx/fastcgi_params;fastcgi_pass unix:$socket;fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;}\n}\n}\n");
    $descriptors=[0=>['pipe','r'],1=>['file',$directory.'/nginx-process.log','a'],2=>['file',$directory.'/nginx-process.log','a']];$processes=[];
    try {
        $processes[]=proc_open([$fpm,'--nodaemonize','--fpm-config',$directory.'/fpm.conf'],$descriptors,$pipes,dirname(__DIR__),$environment);if(isset($pipes[0]))fclose($pipes[0]);
        for($n=0;!file_exists($socket) && $n<50;$n++)usleep(100000);
        if(!file_exists($socket))throw new RuntimeException('PHP-FPM desechable no inició. Revisa el log privado de pruebas.');
        $processes[]=proc_open([$nginx,'-p',$directory.'/','-c',$directory.'/nginx.conf','-g','daemon off;'],$descriptors,$pipes,dirname(__DIR__),$environment);if(isset($pipes[0]))fclose($pipes[0]);
        return $processes;
    }catch(Throwable $error){foreach($processes as $process)if(is_resource($process)){proc_terminate($process);proc_close($process);}throw $error;}
}
