# Unfold

Unfold is a customizable framework for your Nostr-based magazine.

(This is the **Imwald** edition of Unfold.)

## Setup

### Clone the repository

```bash
git clone https://github.com/decent-newsroom/unfold.git
cd unfold
```

### Create the .env file

Copy the example file `.env.dist` and replace placeholders with your actual configuration.

If you have your own MySQL database, comment out the database service in `compose.yaml` and skip root password in `.env`. 
There are additional comments to that effect in the files.

### Configure `config/unfold.yaml`

Before running the application, review and update `config/unfold.yaml` to match your desired magazine settings, theme, and external links. This file controls:
- Magazine name, short name, and description
- Theme and color settings
- Community articles feature
- External footer links
- Other project-specific configuration

Edit the values in `config/unfold.yaml` as needed for your deployment.

### Customizing Theme and Icons

You can override the default theme and icons by adding your own files to `/assets/theme/local/`. To do this:
- Copy the structure and file names from `/assets/theme/default/`.
- Place your custom `theme.css` and icon files in your theme folder.
- Update your configuration in `config/unfold.yaml` to reference your custom theme if needed.

This allows you to easily switch or update the look and feel of your magazine without modifying the default assets.


### Build the Docker containers

For development:
```bash
docker compose build
```

For production (using production overrides), set `APP_ENV=prod` in your `.env` file and run:
```bash
docker compose -f compose.yaml -f compose.prod.yaml build
```


### Start the Docker containers
```bash
docker compose up -d
```


### Run Database Migrations

Before fetching or displaying articles, make sure your database schema is up to date. Run:

```bash
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction
```

### Fetching Articles

To fetch articles from the default relay for the last two months, run:

```bash
docker compose exec php php bin/console articles:get -- '-2 month' 'now'
```

You can adjust the date range as needed. This command will import articles into the local database.
