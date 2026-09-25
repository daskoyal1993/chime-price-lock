<?php defined('FUNNEL_APP') || exit; // rendered by Page::component()
/**
 * Member count, avatars, rating image and before/after stories.
 * Settings: title, title2, avatars[], rating_image, rating_alt, stories[{name, lbs, image}], lbs_unit, story_tag, story_alt.
 */
?>
<section class="<?= cls('pp-members', $s) ?>">
    <div class="wrap">
        <h2 class="section-title"><span class="brand"><?= e($s['title'] ?? '') ?></span><br><?= e($s['title2'] ?? '') ?>
            <span class="avatars"><?php foreach ($s['avatars'] ?? [] as $av): ?><img src="<?= asset($av) ?>" alt=""><?php endforeach; ?></span>
        </h2>
        <?php if (!empty($s['rating_image'])): ?>
            <img class="members-rating" src="<?= asset($s['rating_image']) ?>" alt="<?= e($s['rating_alt'] ?? '') ?>" loading="lazy">
        <?php endif; ?>
        <div class="stories">
            <?php foreach ($s['stories'] ?? [] as $st): ?>
                <figure class="story"><img src="<?= asset($st['image']) ?>" alt="<?= e(fill($s['story_alt'] ?? '{name}', ['name' => $st['name']])) ?>" loading="lazy">
                    <figcaption><span class="story-lbs"><?= e($st['lbs']) ?><small><?= e($s['lbs_unit'] ?? 'lbs') ?></small></span><span><?= e($st['name']) ?></span><span class="story-tag"><?= e($s['story_tag'] ?? '') ?></span></figcaption>
                </figure>
            <?php endforeach; ?>
        </div>
    </div>
</section>
