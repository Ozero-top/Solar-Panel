<?php
/**
 * 通用排序：交换相邻记录并重排 sort
 * $table 仅允许白名单表名（内部调用）
 * items 按分组内排序（各组 sort 独立编号，全表 sort 值允许重复）；
 * item_groups 无分组维度，全表排序。
 */
function move_sort(PDO $pdo, string $table, int $id, string $dir): void
{
    $tables = ['item_groups', 'items'];
    if (!in_array($table, $tables, true) || $id <= 0) {
        fail('参数错误');
    }
    if ($table === 'items') {
        // 卡片必须在「分组内」交换：各组 sort 独立从 0/1 开始，全表 ORDER BY 后相邻行
        // 常常分属不同分组，按全表交换会把两个组的卡片互相换位——重排后两组
        // 的组内顺序都不变，表现为「上移/下移点了没反应」（组首上移、组尾下移必现）
        $st = $pdo->prepare('SELECT group_id FROM items WHERE id = ?');
        $st->execute([$id]);
        $gid = $st->fetchColumn();
        if ($gid === false) {
            fail('记录不存在');
        }
        $st = $pdo->prepare('SELECT id FROM items WHERE group_id = ? ORDER BY sort ASC, id ASC');
        $st->execute([(int)$gid]);
    } else {
        $st = $pdo->query('SELECT id FROM item_groups ORDER BY sort ASC, id ASC');
    }
    $rows = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    $idx = array_search($id, $rows, true);
    if ($idx === false) {
        fail('记录不存在');
    }
    $target = $dir === 'up' ? $idx - 1 : $idx + 1;
    if ($target < 0 || $target >= count($rows)) {
        return; // 已在顶部/底部，无需移动
    }
    [$rows[$idx], $rows[$target]] = [$rows[$target], $rows[$idx]];
    // 环形重排：无论 sort 是否断裂/重复，交换后按数组序归一化为 0..N-1（自愈历史脏数据）
    $upd = $pdo->prepare("UPDATE `{$table}` SET sort = ? WHERE id = ?");
    foreach ($rows as $i => $rid) {
        $upd->execute([$i, (int)$rid]);
    }
}

/**
 * 批量排序（拖拽排序保存）：按提交的 id 顺序整体重写 sort（0..N-1）
 * items 必须传 $groupId，且 ids 必须恰好覆盖该组全部卡片；
 * item_groups 为全表，ids 必须恰好覆盖全部分组——
 * 集合不完全一致时拒绝（防止部分提交造成顺序错乱、或借提交夹带他组/他表 id 越权改序）。
 */
function save_order(PDO $pdo, string $table, array $ids, int $groupId = 0): void
{
    $tables = ['item_groups', 'items'];
    if (!in_array($table, $tables, true)) {
        fail('参数错误');
    }
    $ids = array_values(array_filter(array_map('intval', $ids), static fn ($v) => $v > 0));
    if (!count($ids)) {
        fail('排序数据为空');
    }
    if (count($ids) !== count(array_unique($ids))) {
        fail('排序数据存在重复项');
    }
    if ($table === 'items') {
        if ($groupId <= 0) {
            fail('参数错误');
        }
        $st = $pdo->prepare('SELECT id FROM items WHERE group_id = ?');
        $st->execute([$groupId]);
    } else {
        $st = $pdo->query('SELECT id FROM item_groups');
    }
    $existing = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    $expected = $ids;
    sort($expected);
    $actual = $existing;
    sort($actual);
    if ($expected !== $actual) {
        fail('排序数据与当前记录不一致，请刷新页面后重试');
    }
    if ($pdo->inTransaction()) {
        $upd = $pdo->prepare("UPDATE `{$table}` SET sort = ? WHERE id = ?");
        foreach ($ids as $i => $id) {
            $upd->execute([$i, $id]);
        }
        return;
    }
    $pdo->beginTransaction();
    try {
        $upd = $pdo->prepare("UPDATE `{$table}` SET sort = ? WHERE id = ?");
        foreach ($ids as $i => $id) {
            $upd->execute([$i, $id]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
