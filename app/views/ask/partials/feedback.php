<?php /** The thumbs: up posts at once; down opens a comment box beneath (no modal) with Send and Skip; the chosen thumb stays pressed. Data: qid, rating, in_window */
$swap = $in_window ? ' hx-post="/questions/feedback.php" hx-target="#q-' . $qid . '" hx-swap="outerHTML"' : ' hx-post="/questions/feedback.php" hx-target="#flash"'; ?>
<div class="d-flex flex-wrap align-items-start gap-2" id="thumbs-<?= $qid ?>">
    <form method="post" action="/questions/feedback.php"<?= $swap ?> class="m-0">
        <?= csrf_field() ?><input type="hidden" name="question" value="<?= $qid ?>"><input type="hidden" name="rating" value="up"><input type="hidden" name="partial" value="turn">
        <button type="submit" class="btn btn-touch <?= $rating === 'up' ? 'btn-success' : 'btn-light' ?>" id="thumbs-up-<?= $qid ?>" aria-pressed="<?= $rating === 'up' ? 'true' : 'false' ?>" aria-label="Thumbs up"><i class="feather-thumbs-up"></i></button>
    </form>
    <details class="kn-down" id="thumbs-down-box-<?= $qid ?>">
        <summary class="btn btn-touch <?= $rating === 'down' ? 'btn-danger' : 'btn-light' ?>" id="thumbs-down-<?= $qid ?>" aria-pressed="<?= $rating === 'down' ? 'true' : 'false' ?>" aria-label="Thumbs down"><i class="feather-thumbs-down"></i></summary>
        <form method="post" action="/questions/feedback.php"<?= $swap ?> class="kn-down-form mt-2">
            <?= csrf_field() ?><input type="hidden" name="question" value="<?= $qid ?>"><input type="hidden" name="rating" value="down"><input type="hidden" name="partial" value="turn">
            <label class="form-label fs-12 text-muted" for="thumbs-comment-<?= $qid ?>">What was wrong? (optional)</label>
            <textarea name="comment" id="thumbs-comment-<?= $qid ?>" class="form-control mb-2" rows="2" maxlength="2000"></textarea>
            <div class="d-flex gap-2"><button type="submit" class="btn btn-danger btn-touch" id="thumbs-send-<?= $qid ?>">Send</button><button type="submit" name="skip" value="1" class="btn btn-light btn-touch" id="thumbs-skip-<?= $qid ?>">Skip</button></div>
        </form>
    </details>
</div>
