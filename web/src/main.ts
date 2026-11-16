import { bootstrapApplication } from '@angular/platform-browser';
import { appConfig } from './app/app.config';
import { App } from './app/app';
import { AppShellService } from './app/core/app-shell.service';

bootstrapApplication(App, appConfig).catch((err) => console.error(err));
AppShellService.registerWorker();
