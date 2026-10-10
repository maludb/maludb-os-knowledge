<?php /** Pin (a curator, an answered question): an inline title field, then Pin. Data: q, qid, in_window */ ?>
<details class="kn-pin" id="pin-form-<?= $qid ?>">
    <summary class="btn btn-light btn-touch" id="pin-btn-<?= $qid ?>"><i class="feather-bookmark me-1"></i>Pin</summary>
    <form method="post" action="/questions/pin.php" <?= $in_window ? 'hx-post="/questions/pin.php" hx-target="#q-' . $qid . '" hx-swap="outerHTML"' : 'hx-post="/questions/pin.php" hx-target="#flash"' ?> class="mt-2">
        <?= csrf_field() ?><input type="hidden" name="question" value="<?= $qid ?>"><input type="hidden" name="partial" value="turn">
        <label class="form-label fs-12 text-muted" for="pin-title-<?= $qid ?>">Title</label>
        <input type="text" name="title" id="pin-title-<?= $qid ?>" class="form-control btn-touch mb-2" maxlength="300" value="<?= e(mb_substr(preg_replace('/\s+/', ' ', (string) $q['question']) ?? '', 0, 120)) ?>">
        <button type="submit" class="btn btn-primary btn-touch" id="pin-save-<?= $qid ?>">Pin</button>
    </form>
</details>
