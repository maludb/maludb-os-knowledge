<?php /** The home of a person with no base: ONE card. Data: may (create). A person who may create sees one button and the three steps; anyone else is told nothing has been shared. */ ?>
<div class="card" id="home-first-run"><div class="card-body">
    <div class="empty-state text-center py-4" id="home-empty">
        <span class="avatar-text avatar-lg rounded mb-3"><i class="feather-book-open"></i></span>
        <?php if ($may['create']): ?>
            <a href="/bases/new" class="btn btn-primary btn-touch" id="home-create-base-btn" hx-get="/bases/new" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="/bases/new"><i class="feather-plus me-1"></i>Create a knowledge base</a>
            <div class="fs-12 text-muted mt-3" id="home-steps">Create a base · Add documents · Ask</div>
        <?php else: ?>
            <div class="fw-semibold mb-1" id="home-nothing-shared">Nothing has been shared with you yet.</div>
            <div class="fs-12 text-muted">When someone shares a knowledge base with you, it appears here and you can ask it.</div>
        <?php endif; ?>
    </div>
</div></div>
