/*
 * This file is part of Part-DB (https://github.com/Part-DB/Part-DB-symfony).
 *
 *  Copyright (C) 2019 - 2022 Jan Böhmer (https://github.com/jbtronics)
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU Affero General Public License as published
 *  by the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU Affero General Public License for more details.
 *
 *  You should have received a copy of the GNU Affero General Public License
 *  along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

import {Controller} from "@hotwired/stimulus";

export default class extends Controller
{
    download(event) {
        this.element.href = document.getElementById('pdf_preview').data
    }

    print(event) {
        event.preventDefault();

        const frame = document.getElementById('label_html_preview');
        if (frame?.contentWindow) {
            const doPrint = () => {
                frame.contentWindow.focus();
                frame.contentWindow.print();
            };
            try {
                if (frame.contentDocument?.readyState === 'complete') {
                    doPrint();
                    return;
                }
            } catch {
                // Fall through to the load listener if the iframe is not readable yet.
            }
            frame.addEventListener('load', doPrint, {once: true});
            return;
        }

        const preview = document.getElementById('pdf_preview');
        const dataUri = preview?.getAttribute('data') ?? preview?.data;
        if (!dataUri) {
            return;
        }

        const blob = this.dataUriToBlob(dataUri);
        const url = URL.createObjectURL(blob);
        const iframe = document.createElement('iframe');
        iframe.title = 'Print label';
        iframe.style.position = 'fixed';
        iframe.style.right = '0';
        iframe.style.bottom = '0';
        iframe.style.width = '0';
        iframe.style.height = '0';
        iframe.style.border = '0';
        iframe.src = url;
        iframe.addEventListener('load', () => {
            iframe.contentWindow?.focus();
            iframe.contentWindow?.print();
            window.setTimeout(() => {
                iframe.remove();
                URL.revokeObjectURL(url);
            }, 60000);
        });
        document.body.appendChild(iframe);
    }

    dataUriToBlob(dataUri) {
        const comma = dataUri.indexOf(',');
        const header = dataUri.slice(0, Math.max(comma, 0));
        const body = comma >= 0 ? dataUri.slice(comma + 1) : dataUri;
        const isBase64 = /;base64/i.test(header);
        const bytes = isBase64
            ? Uint8Array.from(atob(body), (character) => character.charCodeAt(0))
            : new TextEncoder().encode(decodeURIComponent(body));

        return new Blob([bytes], {type: 'application/pdf'});
    }
}