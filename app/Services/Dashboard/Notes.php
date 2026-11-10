<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Core\Auth;
use App\Core\HttpError;
use App\Repositories\CommonRepository;
use App\Services\Common\Values;

/** Personal, general notes: ownership is mandatory, including for Super Administrators. */
final class Notes
{
    private int $actor;
    public function __construct(private CommonRepository $repository) { $this->actor=Auth::id() ?? throw new HttpError('No autenticado.',401); }
    private function present(array $row): array
    {
        $row['uuid']=Values::uuidText($row['uuid']);
        foreach (['creada','modificada','recordatorio'] as $field) if ($row[$field]!==null) $row[$field]=str_replace(' ','T',$row[$field]).'Z';
        $row['realizada']=(bool)$row['realizada']; $row['fijada']=(bool)$row['fijada'];
        return $row;
    }
    public function listing(int $offset=0): array
    {
        $offset=Values::integer($offset,0,100000);
        $rows=$this->repository->rows('SELECT uuid,texto,recordatorio,realizada,fijada,creada,modificada FROM dashboard_notas WHERE usuario=? ORDER BY realizada,fijada DESC,recordatorio IS NULL,recordatorio,id DESC LIMIT 51 OFFSET '.$offset,[$this->actor]);
        return ['rows'=>array_map($this->present(...),array_slice($rows,0,50)),'more'=>count($rows)>50];
    }
    public function get(string $uuid): array
    {
        $row=$this->repository->one('SELECT uuid,texto,recordatorio,realizada,fijada,creada,modificada FROM dashboard_notas WHERE uuid=? AND usuario=?',[Values::binary($uuid),$this->actor]) ?? throw new HttpError('Nota no disponible.',404);
        return $this->present($row);
    }
    public function save(?string $uuid,array $data): array
    {
        $text=Values::text($data['texto'] ?? null,1000);
        $reminder=$data['recordatorio'] ?? null;
        if ($reminder==='') $reminder=null;
        if ($reminder!==null) {
            if (!is_string($reminder)) throw new \InvalidArgumentException('Recordatorio inválido.');
            $date=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',$reminder,new \DateTimeZone('UTC'));
            if (!$date || $date->format('Y-m-d\TH:i:s\Z')!==$reminder) throw new \InvalidArgumentException('Recordatorio inválido.');
            $reminder=$date->format('Y-m-d H:i:s');
        }
        $done=Values::integer($data['realizada'] ?? 0,0,1); $pinned=Values::integer($data['fijada'] ?? 0,0,1);
        if ($uuid===null) {
            $uuid=Values::uuid();
            $this->repository->execute('INSERT INTO dashboard_notas (uuid,usuario,texto,recordatorio,realizada,fijada,creada,modificada) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())',[Values::binary($uuid),$this->actor,$text,$reminder,$done,$pinned]);
        } else {
            $this->get($uuid);
            $this->repository->execute('UPDATE dashboard_notas SET texto=?,recordatorio=?,realizada=?,fijada=?,modificada=UTC_TIMESTAMP() WHERE uuid=? AND usuario=?',[$text,$reminder,$done,$pinned,Values::binary($uuid),$this->actor]);
        }
        return $this->get($uuid);
    }
    public function delete(string $uuid): void
    {
        if (!$this->repository->execute('DELETE FROM dashboard_notas WHERE uuid=? AND usuario=?',[Values::binary($uuid),$this->actor])) throw new HttpError('Nota no disponible.',404);
    }
}
