# Makerspace Member Year in Review

Provides a personalized Year in Review dashboard for members, highlighting their contributions, badge earnings, and activity throughout the year.

## Caching Strategy

To ensure high performance during mass traffic (e.g., after an email blast), this module uses an aggressive caching strategy:
- **User Stats**: Cached for 1 year (`makerspace_member_year_review:user_stats:{uid}:{year}`).
- **Community Stats**: Cached for 1 year (`makerspace_member_year_review:community_stats:{year}`).
- **Page Output**: Render array has a `max-age` of 1 year.

## Performance Warm-up (Pantheon)

Before sending out an email to all members, you should "warm" the cache to ensure all data is pre-calculated. This prevents the database from being overwhelmed when many members click the link simultaneously.

### Running the Warm-up Script

1. Ensure `precalculate_stats.php` is committed to the module directory and pushed to Pantheon.
2. Run the following command using Terminus (replace `SITE` and `ENV` with your site name and environment, e.g., `makehaven-website.live`):

```bash
terminus drush SITE.ENV -- scr modules/custom/makerspace_member_year_review/precalculate_stats.php
```

The script will iterate through all active members and pre-calculate their statistics, storing them in the cache for 1 year.
