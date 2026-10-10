<?php /** Dismiss with an optional reason. Data: pid */ $pid = (int) $pid; ?>
<details class="mt-2" id="proposal-dismiss-<?= $pid ?>">
    <summary class="btn btn-light btn-touch w-100 d-flex align-items-center justify-content-center" id="proposal-dismiss-<?= $pid ?>-open">Dismiss</summary>
    <form method="post" action="/proposals/dismiss.php" hx-post="/proposals/dismiss.php" hx-target="#flash" class="pt-2" id="proposal-dismiss-<?= $pid ?>-form">
        <?= csrf_field() ?><input type="hidden" name="proposal" value="<?= $pid ?>">
        <label class="form-label fs-12 text-muted" for="proposal-dismiss-<?= $pid ?>-note">Why not? (optional — the Archivist reads it)</label>
        <textarea name="note" id="proposal-dismiss-<?= $pid ?>-note" class="form-control mb-2" rows="2" maxlength="500"></textarea>
        <button type="submit" class="btn btn-light btn-touch w-100" id="proposal-dismiss-<?= $pid ?>-btn">Dismiss this proposal</button>
    </form>
</details>
