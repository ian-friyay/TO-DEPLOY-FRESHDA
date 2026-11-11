<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Collaborator Setup Guide

To run Fresh-DA on your local machine:

1. **Clone the repository:**
   ```bash
   git clone https://github.com/adrian-viernes/Freshda.git
   cd Freshda/Freshda
   ```

2. **Install dependencies:**
   ```bash
   composer install
   npm install
   ```

3. **Configure environment:**
   ```bash
   copy .env.example .env
   php artisan key:generate
   ```
   Add your team `ROBOFLOW_API_KEY` in `.env` to enable live fish AI scanning:
   ```env
   ROBOFLOW_API_KEY=your_team_key_here
   ```

4. **Prepare database & storage symlink:**
   ```bash
   php artisan migrate
   php artisan storage:link
   ```

5. **Start the local server:**
   ```bash
   php artisan serve
   ```
   Open `http://127.0.0.1:8000` in your browser.

## Roboflow Workflow Integration

Fresh-DA integrates the Roboflow Serverless Workflow **"fresh-da-project-5-vit-base-patch16-224-in21k-t1 vfresh-da-project-5-vit-base-patch16-224-in21k-t1 Logic"** for species identification and freshness analysis.

### Configuration

Add the following environment variables to your `.env` file:

```env
ROBOFLOW_API_KEY=your_roboflow_api_key_here
ROBOFLOW_WORKSPACE=evelyns-workspace-gfs2g
ROBOFLOW_WORKFLOW_ID=fresh-da-project-5-vit-base-patch16-224-in21k-t1-vfresh-da-project-5-vit-base-patch16-224-in21k-t1-logic
ROBOFLOW_API_URL=https://serverless.roboflow.com
ROBOFLOW_TIMEOUT=30
ROBOFLOW_RETRY_TIMES=3
ROBOFLOW_RETRY_SLEEP_MS=500
ROBOFLOW_MIN_CONFIDENCE=0.60
```

- **Endpoint**: `POST https://serverless.roboflow.com/evelyns-workspace-gfs2g/workflows/fresh-da-project-5-vit-base-patch16-224-in21k-t1-vfresh-da-project-5-vit-base-patch16-224-in21k-t1-logic`
- **Model**: `evelyns-workspace-gfs2g/fresh-da-project-5-vit-base-patch16-224-in21k-t1` (Vision Transformer ViT-Base)
- **Authentication**: `Authorization: Bearer <ROBOFLOW_API_KEY>`
- **Input schema**: Accepts local files (converted to base64) or HTTPS URLs: `{"inputs": {"image": {"type": "base64", "value": "..."}}}`. Optional parameters: `confidence` (float, default 0.4), `model_id`.
- **Output schema**: Returns `predictions` dictionary containing classification predictions (`freshness_*` and `species_*`), `inference_id`, and `model_id`.
- **Service**: Handled via `App\Services\RoboflowWorkflowClient` and integrated into `App\Services\FishAiService`.
- **Offline / Error Resiliency**: If the Roboflow service is offline or unconfigured, the application falls back gracefully to deterministic simulated scans so scans never crash.

### Running Smoke Tests

Run the dedicated PHPUnit test suite:

```bash
php artisan test --filter=RoboflowWorkflowSmokeTest
```

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
