<?php
declare(strict_types=1);

/** @param list<array{id:int, code:string, name_ar:string, group_key?:string, group_label?:string}> $accounts */
function fin_cash_bank_select_options(array $accounts, int $selectedId = 0): string
{
    $html = '';
    $cashGroup = '';
    foreach ($accounts as $acc) {
        $gk = (string) ($acc['group_key'] ?? 'cash');
        $gl = (string) ($acc['group_label'] ?? '');
        if ($gk !== $cashGroup) {
            if ($cashGroup !== '') {
                $html .= '</optgroup>';
            }
            $cashGroup = $gk;
            if ($gl !== '') {
                $html .= '<optgroup label="' . esc($gl) . '">';
            }
        }
        $id = (int) ($acc['id'] ?? 0);
        $html .= '<option value="' . $id . '"'
            . ($id === $selectedId ? ' selected' : '')
            . '>' . esc(trim((string) ($acc['code'] ?? '')) . ' — ' . trim((string) ($acc['name_ar'] ?? '')))
            . '</option>';
    }
    if ($cashGroup !== '') {
        $html .= '</optgroup>';
    }

    return $html;
}
