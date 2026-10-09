<?php /** What the engine is told (screen `guidelines-compiled`). Data: b, prompt, system, vocabulary, engine, tabs */
$id = (int) $b['base_id']; ?>
<?= view('shared/header.php', ['id' => 'guidelines-compiled', 'title' => 'What the engine is told', 'crumbs' => [['Home', '/'], ['Bases', '/bases'], [$b['name'], '/bases/' . $id], ['Guidelines', '/bases/' . $id . '/guidelines'], ['Compiled', null]],
    'back' => back_link() ?? ['/bases/' . $id . '/guidelines', 'the guidelines']]) ?>
<div class="main-content" id="guidelines-compiled-content">
    <?= view('bases/partials/base-tabs.php', ['tabs' => $tabs, 'active' => 'guidelines']) ?>
    <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">The extraction prompt</h5></div><div class="card-body">
        <div class="fs-12 text-muted mb-2" id="compiled-facts">SHA-256 <code class="text-break"><?= e($prompt['sha256']) ?></code> · <?= number_format((int) $prompt['chars']) ?> characters ·
            <?= (int) $prompt['lines']['extract'] ?> EXTRACT · <?= (int) $prompt['lines']['subject_type'] ?> SUBJECT TYPE · <?= (int) $prompt['lines']['vocabulary_note'] ?> VOCABULARY NOTE · <?= (int) $prompt['lines']['term'] ?> TERM · <?= (int) $prompt['lines']['alias'] ?> ALIAS · <?= (int) $prompt['lines']['exclude'] ?> EXCLUDE</div>
        <?php if ($engine['checked']): ?><div class="mb-2" id="compiled-engine"><?= $engine['holds'] ? chip(['the engine has it', 'success']) : chip(['the engine will get it before the next build', 'warning']) ?></div><?php endif; ?>
        <?php foreach ($prompt['truncated'] as $block => $n): ?><div class="alert alert-warning py-2 fs-12"><?= (int) $n ?> <?= e(strtoupper(str_replace('_', ' ', $block))) ?> line<?= $n === 1 ? '' : 's' ?> did not fit.</div><?php endforeach; ?>
        <?php foreach ($prompt['conflicts'] as $c): ?><div class="alert alert-warning py-2 fs-12">"<?= e($c['alias']) ?>" is claimed by <?= e($c['kept_for']) ?> and <?= e($c['dropped_for']) ?> — only <?= e($c['kept_for']) ?> keeps it.</div><?php endforeach; ?>
        <pre class="kn-preview-pre" id="compiled-prompt"><?= e($prompt['prompt_template']) ?></pre>
    </div></div>
    <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">How the base answers</h5></div><div class="card-body">
        <div class="fs-12 text-muted mb-2">Told to the model with every question; answering and audience guidelines apply at once.</div>
        <pre class="kn-preview-pre" id="compiled-system"><?= e($system['system']) ?></pre>
    </div></div>
    <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">The terms as the engine gets them</h5></div><div class="card-body">
        <?php if ($vocabulary['terms'] === []): ?><div class="fs-12 text-muted">No terms yet.</div><?php else: ?>
            <div class="table-responsive"><table class="table table-sm mb-0" id="compiled-terms"><thead><tr><th>Term</th><th>Type</th><th>Aliases</th></tr></thead><tbody>
            <?php foreach ($vocabulary['terms'] as $t): ?><tr><td><?= e($t['canonical_name']) ?></td><td><?= e($t['subject_type']) ?></td><td class="fs-12"><?= e(implode(', ', $t['aliases'])) ?></td></tr><?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>
    </div></div>
</div>
