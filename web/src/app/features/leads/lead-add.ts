import { Component, inject, input, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { readErrors } from '../../core/http-errors';
import { Lead, LeadOptions, LeadsService } from '../../core/leads.service';
import { Sheet } from '../../shared/sheet';

@Component({
  selector: 'app-lead-add',
  imports: [FormsModule, Sheet],
  template: `
    <app-sheet title="Add lead" subtitle="Walk-ins, calls and referrals" width="520px" (closed)="closed.emit()">
      @if (message()) { <div class="alert error">{{ message() }}</div> }
      <form (ngSubmit)="save()">
        <div class="row-2">
          <div class="field compact" [class.invalid]="err('full_name')"><label for="la-n">Name <span class="req">*</span></label><input id="la-n" name="n" [(ngModel)]="f.full_name" />
            @if (err('full_name')) { <div class="err">{{ err('full_name') }}</div> }</div>
          <div class="field compact" [class.invalid]="err('phone')"><label for="la-p">Phone number</label><input id="la-p" name="p" type="tel" placeholder="+91" [(ngModel)]="f.phone" />
            @if (err('phone')) { <div class="err">{{ err('phone') }}</div> }</div>
          <div class="field compact" [class.invalid]="err('email')"><label for="la-e">Email ID</label><input id="la-e" name="e" type="email" [(ngModel)]="f.email" />
            @if (err('email')) { <div class="err">{{ err('email') }}</div> }</div>
          <div class="field compact"><label for="la-c">Location (city)</label><input id="la-c" name="c" [(ngModel)]="f.city" /></div>
          <div class="field compact"><label for="la-ad">Source / AD name</label><input id="la-ad" name="ad" placeholder="Referral, walk-in…" [(ngModel)]="f.ad_name" /></div>
          <div class="field compact"><label for="la-pk">Package</label>
            <select id="la-pk" name="pk" [(ngModel)]="f.package"><option [ngValue]="null">—</option>
              @for (p of options().packages; track p) { <option [value]="p">{{ p }}</option> }</select></div>
        </div>
        <div class="field"><label for="la-r">Requirement</label><textarea id="la-r" name="r" rows="2" [(ngModel)]="f.requirement"></textarea></div>
        <div class="field"><label for="la-no">Notes / remarks</label><textarea id="la-no" name="no" rows="2" [(ngModel)]="f.notes"></textarea></div>
        <div class="form-actions">
          <button type="button" class="btn-ghost" (click)="closed.emit()">Cancel</button>
          <button class="btn" type="submit" [disabled]="busy()">Add lead</button>
        </div>
      </form>
    </app-sheet>
  `,
})
export class LeadAdd {
  options = input.required<LeadOptions>();
  closed = output<void>();
  saved = output<Lead>();
  private api = inject(LeadsService);
  protected f: Partial<Lead> = { package: null };
  protected readonly busy = signal(false);
  protected readonly message = signal('');
  protected readonly errors = signal<Record<string, string>>({});

  err(k: string): string {
    return this.errors()[k] ?? '';
  }

  save(): void {
    this.busy.set(true);
    this.errors.set({});
    this.message.set('');
    this.api.create(this.f).subscribe({
      next: (l) => this.saved.emit(l),
      error: (e) => {
        const r = readErrors(e);
        this.errors.set(r.fields);
        this.message.set(r.message);
        this.busy.set(false);
      },
    });
  }
}
