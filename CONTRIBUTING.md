# Contributing

Contributions are welcome through issues and pull requests.

## Development setup

1. Copy `config.example.php` to `config.php` and add local MySQL credentials.
2. Create an empty MySQL database and import `database/schema.sql`.
3. Serve the project with PHP 8.0+ and the `curl` and `pdo_mysql` extensions.
4. Verify the public lookup, sharable-link creation, result view, expiry, and
   rate-limit behavior before submitting a change.

## Pull requests

- Keep credentials and diagnostic data out of commits.
- Preserve consent and privacy behavior when changing capture routes.
- Escape rendered values and use prepared statements for database queries.
- Explain user-visible behavior changes and include validation steps.
- Keep unrelated formatting changes out of the same pull request.

By contributing, you agree that your contribution is licensed under the MIT
License included with this project.
