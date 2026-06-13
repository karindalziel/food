# Food Tracker

A personal food tracking web app built with PHP and SQLite. Log meals, track fiber, protein, and produce against daily goals, and look up nutrition data from the USDA food database. Designed to run on shared PHP hosting with no additional software required.

The intent is for app to be loaded into a password protected folder.

---

## Requirements

- PHP 8.0 or later (tested on PHP 8.3)
- SQLite support enabled in PHP (enabled by default on most shared hosts)
- Write access to the `data/` directory
- An outbound HTTP connection from the server (for USDA lookups)
- A free USDA FoodData Central API key (see First-Time Setup below)

---

## Installation

1. Upload all files in `site_files/` to your web server. You can place them in the document root or a subdirectory.

2. Make sure the `data/` directory is writable by the web server. On most shared hosts this is already the case. If not, set its permissions to `755`.

3. The `.htaccess` file in `site_files/` disables directory listing. The `data/.htaccess` blocks direct web access to the database and config files. Confirm your host honours `.htaccess` files (Apache does by default; check with your host if you are unsure).

   PHP error settings (errors logged but not displayed) are controlled by `php.ini` in `site_files/`. This works on hosts running PHP as FastCGI or PHP-FPM, which is common on shared hosting. If your host runs PHP as an Apache module (mod_php) and ignores `php.ini`, those settings can instead be added to `.htaccess` as `php_flag display_errors Off` and `php_flag log_errors On`.

4. Visit the app in your browser. If the config file is not yet present, you will be redirected to the setup page automatically.

---

## First-Time Setup

The app requires a free API key from the USDA FoodData Central service, which is used to look up nutrition information for whole foods.

To get your key:

1. Go to [fdc.nal.usda.gov/api-guide.html](https://fdc.nal.usda.gov/api-guide.html)
2. Click **Sign up / Request an API Key**
3. Enter your name and email address
4. Check your email — the key arrives within a few minutes
5. Paste the key into the setup screen and click **Verify and Save**

The key is verified against the USDA API before being saved. Once saved, the setup screen will not appear again.

The key is stored in `data/config.php`, which is protected from direct web access by `data/.htaccess`.

---

## How It Works

### People and goals

The app supports multiple people tracked on the same installation. Each person has daily goals for:

- Fiber (grams)
- Protein (grams)
- Produce (servings)

Goals are set in Settings. Each person gets a bookmarkable URL (e.g. `index.php?u=2`) so different people can access their own data without logging in. To switch between people, use the name links in the page header or bookmark your personal URL.

### Food library

The food library is a shared list of foods used across all people. Each food entry stores:

- Name
- A quantity description (e.g. "1 cup cooked")
- Total grams for that quantity
- Grams of fiber per serving
- Grams of protein per serving
- Produce servings per serving

When you log a meal, you choose foods from this library and optionally adjust the portion. All nutrition calculations are based on the portion multiplier: a multiplier of `1` is one full serving, `0.5` is half, `2` is double, and so on.

You can also enter grams directly and the app will calculate the portion multiplier for you.

### Logging a meal

1. Go to **Log Meal** from the bottom navigation bar.
2. Set the date and time (defaults to now).
3. Add an optional note (e.g. "breakfast", "post-workout snack").
4. Search for foods from your library and add them to the meal.
5. Adjust the portion multiplier or enter a gram amount for each food.
6. Submit to save.

If a food you want is not in the library yet, click **+ New Food** to add it without leaving the log screen. The new food is saved to the library and added to your meal at the same time.

You can also search the USDA database from the new food form to pre-fill fiber and protein values. Grams scale automatically if you change the serving size.

### Named meals

If you eat the same combination of foods regularly, you can save it as a named meal. Check the **Save foods as a named meal** box before submitting. Named meals can be loaded at the top of the Log Meal screen and will add all their foods at once.

Named meals are managed (and deleted) in Settings.

### Today view

The home page shows today's progress bars for fiber, protein, and produce, plus a list of today's meals. A warning indicator (⚠️) appears next to any meal that has no foods logged, since those meals are excluded from the totals.

### Reports

The Reports page shows a week-at-a-glance grid. Click any day to see that day's meals and totals. Use the Previous and Next buttons to navigate between weeks.

### Importing and exporting data

**Export** produces a CSV file containing all logged meals for the active person, with one row per food item. You can export all data or a specific date range.

**Import** accepts a CSV in the same format as the export. It can:

- Create foods that do not yet exist in the library
- Update or keep existing food entries (your choice)
- Skip or replace meals that already exist for the same date and time

A dry-run option shows you what would be imported without saving anything.

Both import and export are accessible from Settings.

---

## File Structure

```
site_files/
  index.php              Today dashboard
  log.php                Log and edit meals
  meal.php               Meal detail view
  foods.php              Food library (browse, add, edit)
  reports.php            Weekly reports
  settings.php           People, goals, named meals, import/export links
  import.php             CSV import
  export.php             CSV export
  setup.php              First-time API key setup
  api.php                Internal JSON endpoints used by the UI
  db.php                 Database connection, schema init, shared helpers
  layout.php             Shared HTML shell, navigation, CSS, shared JS
  schema.sql             SQLite schema (applied automatically on first run)
  _food_form_fields.php  Shared food entry form partial
  .htaccess              Directory listing disabled
  php.ini                PHP error settings (errors logged, not displayed)
  data/
    diet.db              SQLite database (created automatically)
    config.php           USDA API key (created by setup)
    .htaccess            Blocks direct web access to data/ files
```

---

## Security Notes

- The `data/` directory is protected by `.htaccess` to prevent direct access to the database and config files. Confirm your host enforces this.
- All forms use CSRF tokens.
- PHP error display is turned off via `php.ini`; errors are logged server-side only.
- There is no login system. Access control is through bookmarked URLs. This is suitable for personal use on a private or password-protected hosting account. It is not appropriate for a public-facing installation where data privacy between users is required.
