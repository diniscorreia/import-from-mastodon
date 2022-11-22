*This is a fork of [Jan Boddez](https://github.com/janboddez)’s [Import From Mastodon](https://github.com/janboddez/import-from-mastodon). It adds an additional option to import favourites as well.*

*A small limitation: Mastodon’s API doesn’t currently return a timestamp for when a toot was favourited, so the imported faves are created with the current date (while not great, seems like a better option than using the original toot’s date).*

---

# Import From Mastodon
Automatically turn toots—short messages on [Mastodon](https://joinmastodon.org/)—into WordPress posts.

## Installation
For now, download the [ZIP file](https://github.com/janboddez/import-from-mastodon/archive/refs/heads/master.zip). Upload to `wp-content/plugins` and unzip. (Optionally) rename the resulting folder from `import-from-mastodon-master` to `import-from-mastodon`. (This last step may help resolve possible future conflicts.)

After activating the plugin, visit Settings > Import From Mastodon. Fill out your instance's URL as well as the other options. Press Save Changes.

Then, on the same settings page, click the Authorize Access button. This should take you to your Mastodon instance and allow you to authorize WordPress to read from your timeline. (We don't request write access.) You'll be automatically redirected to WordPress afterward.

**Note**: WordPress won't immediately start importing toots, but will take a couple minutes before doing so. I'll "fix" this in a next version.

## How It Works
Every 15 minutes—more or less, because WordPress's cron system isn't quite exact—your Mastodon timeline is polled for new toots, which are then imported as the post type of your choice.

By default, only the 40 most recent toots are considered. (This is also the maximum value the Mastodon API will allow. Unless you create more than 40 toots per 15 minutes, this shouldn't be an issue.)

### Of Note
The very first time this plugin does its thing, up to 40 (per the remark above) toots are imported. (This might get changed to just one in a next version.) From then on, only the _most recent_ toots are taken into account. (We use a `since_id` API param to tell Mastodon which toots to look up for us. This `since_id` corresponds with the most recently imported _existing_, i.e., in WordPress, post.)

If all that sounds confusing, it is. Well, maybe not. Regardless, it's okay to just forget about it.

## Boosts and Replies, and Custom Formatting
It's possible to either exclude or include boosts or replies.

Just, uh, know that boosts and replies may look a bit _off_, and miss some context.

### Threading
There isn't any. Replies-to-self, when replies are enabled, are imported as separate, new posts, not comments. (Again, this would make a nice add-on plugin.)

## Tags and Blocklist
**Tags**: (Optional) Poll for toots with any of these tags only (and ignore all other toots). Separate tags by commas.  
**Blocklist**: (Optional) Ignore toots with any of these words. (One word, or part of a word, per line.) Beware partial matches!

## Images
Images are downloaded and [attached](https://wordpress.org/support/article/using-image-and-file-attachments/#attachment-to-a-post) to imported toots, but **not** (yet) automatically included _in_ the post.

The first image, however, is set as the freshly imported post's Featured Image. Of course, this behavior, too, can be changed:
```
add_filter( 'import_from_mastodon_featured_image', '__return_false' ); // Do not set Featured Images
```

## Miscellaneous
There are in fact a few more filters and settings that I might eventually document a bit better (though the settings should kind of speak for themselves).

### Custom Post Types
Like, if you wanted your imported toots be a Custom Post Type (rather than the default `post`):
```
add_filter( 'import_from_mastodon_args', function( $args, $status ) {
	$args['post_type'] = 'iwcpt_note'; // My "Note" type.

	unset( $args['post_category'] ); // Because my CPT may not support the "category" taxonomy at all.

	return $args;
}, 10, 2 );

```
Above `$args` are in fact the very arguments passed on to [`wp_insert_post()`](https://developer.wordpress.org/reference/functions/wp_insert_post/#parameters). (Endless possibilities!)
