<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Services\Common\Values;
use InvalidArgumentException;
use PDO;

final class PersonMapRepository
{
    public const ADDRESS_FILTER = 'd.latitud BETWEEN ? AND ? AND d.longitud BETWEEN ? AND ?
        AND d.id=(SELECT first_address.id FROM personas_domicilios first_address
                  WHERE first_address.persona=p.id ORDER BY principal DESC,id LIMIT 1)';
    private const PAGE_SIZE = 25;

    public function __construct(private PDO $db) {}

    public static function viewport(array $query): array
    {
        $area = [];
        foreach (['south'=>90,'north'=>90,'west'=>180,'east'=>180] as $key=>$bound) {
            $value = filter_var($query[$key] ?? null,FILTER_VALIDATE_FLOAT);
            if ($value === false || abs($value)>$bound) throw new InvalidArgumentException('La zona del mapa no es válida.');
            $area[$key] = $value;
        }
        if ($area['south']>$area['north'] || $area['west']>$area['east']) throw new InvalidArgumentException('La zona del mapa no es válida.');
        return [
            ...$area,
            'rows'=>max(1,min(16,(int)($query['rows'] ?? 8))),
            'columns'=>max(1,min(24,(int)($query['columns'] ?? 12))),
            'list'=>($query['view'] ?? null)==='people',
            'offset'=>max(0,(int)($query['offset'] ?? 0)),
        ];
    }

    // Las consultas consumidoras definen las uniones, el alcance y las columnas (alias SQL internos).
    public function rows(array $area, string $from, array $params, array $columns): array
    {
        $fields = [];
        foreach (['uuid'=>'p.uuid',...$columns] as $alias=>$expression) {
            $fields[] = ($area['list'] ? $expression : "IF(COUNT(*)=1,MIN($expression),NULL)") . ' ' . $alias;
        }
        $projection = implode(',',$fields);
        if ($area['list']) {
            $limit = self::PAGE_SIZE+1;
            $sql = "SELECT 1 quantity,$projection,d.latitud latitude,d.longitud longitude $from
                    ORDER BY p.id LIMIT $limit OFFSET " . $area['offset'];
        } else {
            $sql = "SELECT COUNT(*) quantity,$projection,AVG(d.latitud) latitude,AVG(d.longitud) longitude,
                MIN(d.latitud) south,MAX(d.latitud) north,MIN(d.longitud) west,MAX(d.longitud) east $from
                GROUP BY FLOOR((d.latitud-?)/?), FLOOR((d.longitud-?)/?)";
            $params = [...$params,$area['south'],max(0.0000001,($area['north']-$area['south'])/$area['rows']),
                $area['west'],max(0.0000001,($area['east']-$area['west'])/$area['columns'])];
        }
        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function result(array $rows, bool $list, array $fields): array
    {
        $more = $list && count($rows)>self::PAGE_SIZE;
        if ($more) array_pop($rows);
        $points = [];
        $fields = array_fill_keys($fields,true);
        foreach ($rows as $row) {
            $point = ['latitude'=>(float)$row['latitude'],'longitude'=>(float)$row['longitude'],'count'=>(int)$row['quantity']];
            if ($point['count']===1) {
                $point += ['id'=>Values::uuidText($row['uuid']),...array_intersect_key($row,$fields)];
            } else {
                $point['bounds'] = array_map('floatval',array_intersect_key($row,array_flip(['south','north','east','west'])));
            }
            $points[] = $point;
        }
        return ['points'=>$points,'has_more'=>$more];
    }
}
