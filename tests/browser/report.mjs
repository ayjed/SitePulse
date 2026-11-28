import { mkdirSync, writeFileSync } from 'node:fs';

export default class BrowserReport {
    results = [];

    onTestEnd(test, result) {
        this.results.push({ test: test.title, result: result.status });
    }

    onEnd() {
        mkdirSync('storage/app/private/browser-summary', { recursive: true });
        writeFileSync('storage/app/private/browser-summary/results.json', JSON.stringify(this.results, null, 2));
    }
}
