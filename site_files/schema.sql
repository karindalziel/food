CREATE TABLE IF NOT EXISTS people (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    goal_fiber_g REAL DEFAULT 0,
    goal_protein_g REAL DEFAULT 0,
    goal_produce_servings REAL DEFAULT 0,
    header_color TEXT DEFAULT '#2d6a4f',  -- hex color for the app header; user-customizable per person
    notes TEXT,                            -- freeform personal notes; not used by the app
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS foods (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    quantity_description TEXT,  -- human-readable serving label, e.g. "1 cup cooked"
    grams REAL,                 -- nullable: omitted when serving size is not meaningful
    grams_fiber REAL DEFAULT 0,
    grams_protein REAL DEFAULT 0,
    servings_produce REAL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS meals (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    person_id INTEGER NOT NULL REFERENCES people(id),
    eaten_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    notes TEXT,
    is_planned INTEGER NOT NULL DEFAULT 0,  -- 1 = future/scheduled meal; 0 = already eaten
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS meal_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    meal_id INTEGER NOT NULL REFERENCES meals(id) ON DELETE CASCADE,
    food_id INTEGER NOT NULL REFERENCES foods(id),  -- intentionally no ON DELETE CASCADE: deleting a meal should not remove foods from the library
    portion_multiplier REAL NOT NULL DEFAULT 1.0
);

CREATE TABLE IF NOT EXISTS meal_templates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS meal_template_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    template_id INTEGER NOT NULL REFERENCES meal_templates(id) ON DELETE CASCADE,
    food_id INTEGER NOT NULL REFERENCES foods(id),
    portion_multiplier REAL NOT NULL DEFAULT 1.0
);
