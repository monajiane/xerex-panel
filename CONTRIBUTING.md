# Contributing to Xerex Panel

Thanks for your interest in making Xerex better! 🎉

## Code of Conduct

Be respectful, assume good faith, focus on technical merit.

## How to contribute

1. **Open an issue first** for non-trivial changes. Describe the problem
   and your proposed solution.

2. **Fork the repo** and create a feature branch:
   ```bash
   git checkout -b feat/my-amazing-feature
   ```

3. **Follow the coding style**:
   - PHP: PSR-12, run `./vendor/bin/pint` before committing
   - Vue: `<script setup>` Composition API, Tailwind for styles
   - Commit messages: `feat: ...`, `fix: ...`, `docs: ...`, `chore: ...`

4. **Write tests** for new features. We use PHPUnit for the backend
   and Vitest for the frontend (planned).

5. **Update docs** if you change user-facing behavior.

6. **Open a Pull Request** against the `main` branch.

## Development setup

See [`docs/installation.md`](docs/installation.md).

```bash
# Install deps
composer install
npm install

# Run tests
php artisan test

# Lint
./vendor/bin/pint
```

## Project conventions

### Backend (Laravel)
- All controllers go in `app/Http/Controllers/Api/` (or `Web/`)
- All business logic goes in `app/Services/`
- Database access should go through the repository interfaces
  in `app/Repositories/Contracts/`
- Use form-request classes for validation when > 3 rules
- Eager-load relationships to prevent N+1 (Laravel strict mode is on)

### Frontend (Vue)
- Use Pinia for state, not raw `ref()` in components
- Use the `auth.can('permission.name')` helper for permission checks
- Use Tailwind utility classes, no inline styles
- Components: PascalCase, files in `resources/js/components/`
- Views: PascalCase + `View` suffix, in `resources/js/views/`

### Database
- Migrations: timestamp prefix `YYYY_MM_DD_NNNNNNN_*.php`
- Always include `down()` method
- Add indexes on columns used in `WHERE` clauses
- Use UUIDs for public IDs, auto-increment for foreign keys

## Reporting security issues

**Please do not open public issues for security bugs.** Email
`security@xerex.local` (or your contact of choice) and we'll respond
within 48 hours.

## License

By contributing, you agree that your contributions will be licensed
under the [MIT License](LICENSE).
