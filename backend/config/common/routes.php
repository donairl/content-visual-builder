<?php

declare(strict_types=1);

use App\Api;
use App\Api\Shared\RequireApiUser;
use App\Web;
use Yiisoft\Http\Method;
use Yiisoft\Router\Group;
use Yiisoft\Router\Route;

/**
 * Static paths win over the `/{slug}` catch-all, which is why the reserved slugs (PageForm::
 * RESERVED_SLUGS) are exactly the first segments used here.
 */
return [
    Route::get('/')
        ->action(Web\Home\Action::class)
        ->name('home'),

    Route::get('/login')
        ->action(Web\Auth\Login\ShowAction::class)
        ->name('login'),
    Route::post('/login')
        ->action(Web\Auth\Login\SubmitAction::class)
        ->name('login.submit'),
    Route::post('/logout')
        ->action(Web\Auth\Logout\Action::class)
        ->name('logout'),

    Group::create('/api')
        ->routes(
            Group::create()
                ->middleware(RequireApiUser::class)
                ->routes(
                    Route::get('/session')->action(Api\Session\ShowAction::class)->name('api.session'),
                    Route::get('/pages')->action(Api\Pages\IndexAction::class)->name('api.pages.index'),
                    Route::post('/pages')->action(Api\Pages\CreateAction::class)->name('api.pages.create'),
                    Route::get('/pages/{id:\d+}')->action(Api\Pages\ShowAction::class)->name('api.pages.show'),
                    Route::patch('/pages/{id:\d+}')->action(Api\Pages\UpdateAction::class)->name('api.pages.update'),
                    Route::delete('/pages/{id:\d+}')->action(Api\Pages\DeleteAction::class)->name('api.pages.delete'),
                    Route::put('/pages/{id:\d+}/draft')->action(Api\Pages\SaveDraftAction::class)->name('api.pages.draft'),
                    Route::post('/pages/{id:\d+}/publish')->action(Api\Pages\PublishAction::class)->name('api.pages.publish'),
                    Route::post('/pages/{id:\d+}/unpublish')->action(Api\Pages\UnpublishAction::class)->name('api.pages.unpublish'),
                    Route::get('/pages/{id:\d+}/revisions')->action(Api\Revisions\IndexAction::class)->name('api.revisions.index'),
                    Route::get('/pages/{id:\d+}/revisions/{revision:\d+}')->action(Api\Revisions\ShowAction::class)->name('api.revisions.show'),
                    Route::post('/media')->action(Api\Media\UploadAction::class)->name('api.media.upload'),
                    Route::get('/components')->action(Api\Components\IndexAction::class)->name('api.components.index'),
                    Route::post('/components')->action(Api\Components\CreateAction::class)->name('api.components.create'),
                    Route::get('/components/{id:\d+}')->action(Api\Components\ShowAction::class)->name('api.components.show'),
                    Route::patch('/components/{id:\d+}')->action(Api\Components\UpdateAction::class)->name('api.components.update'),
                    Route::delete('/components/{id:\d+}')->action(Api\Components\DeleteAction::class)->name('api.components.delete'),
                    Route::put('/components/{id:\d+}/draft')->action(Api\Components\SaveDraftAction::class)->name('api.components.draft'),
                    Route::post('/components/{id:\d+}/publish')->action(Api\Components\PublishAction::class)->name('api.components.publish'),
                    Route::get('/templates')->action(Api\Templates\IndexAction::class)->name('api.templates.index'),
                    Route::post('/templates')->action(Api\Templates\CreateAction::class)->name('api.templates.create'),
                    Route::get('/templates/{id:\d+}')->action(Api\Templates\ShowAction::class)->name('api.templates.show'),
                    Route::patch('/templates/{id:\d+}')->action(Api\Templates\UpdateAction::class)->name('api.templates.update'),
                    Route::delete('/templates/{id:\d+}')->action(Api\Templates\DeleteAction::class)->name('api.templates.delete'),
                    Route::get('/globals')->action(Api\Globals\ShowAction::class)->name('api.globals.show'),
                    Route::put('/globals')->action(Api\Globals\SaveAction::class)->name('api.globals.save'),
                ),
            // Anything else under /api answers JSON 404, for guests too.
            Route::methods(Method::ALL, '[/{path:.*}]')->action(Api\NotFound\Action::class)->name('api.not-found'),
        ),

    Route::get('/assets/cvb-base.{hash:[a-f0-9]+}.css')
        ->action(Web\Assets\BaseCssAction::class)
        ->name('assets.base'),
    Route::get('/assets/page/{id:\d+}.{hash:[a-f0-9]+}.css')
        ->action(Web\Assets\PageCssAction::class)
        ->name('assets.page'),

    Route::get('/preview/{id:\d+}')
        ->action(Web\Preview\Action::class)
        ->name('preview'),

    Route::get('/editor[/{path:.*}]')
        ->action(Web\Editor\Action::class)
        ->name('editor'),

    Route::get('/{slug:[a-z0-9]+(?:-[a-z0-9]+)*}')
        ->action(Web\PublicPage\Action::class)
        ->name('page'),
];
