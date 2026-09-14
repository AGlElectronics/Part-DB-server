/*
 * This file is part of Part-DB (https://github.com/Part-DB/Part-DB-symfony).
 *
 * Copyright (C) 2026 Part-DB contributors
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published
 * by the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

import {Controller} from "@hotwired/stimulus";

export default class extends Controller
{
    static values = {
        protocol: String,
        filename: {type: String, default: 'partdb-ptouch.ptjob'},
    }

    print(event) {
        event.preventDefault();

        const jobNode = document.getElementById('bpac_print_job');
        const json = jobNode?.textContent?.trim() ?? '';
        const protocol = this.hasProtocolValue ? this.protocolValue : this.toProtocolUrl(json);

        if (protocol && protocol.length <= 14000) {
            window.location.href = protocol;
            return;
        }

        if (!json) {
            return;
        }

        const blob = new Blob([json], {type: 'application/json'});
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = this.filenameValue;
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.setTimeout(() => URL.revokeObjectURL(url), 1000);
    }

    toProtocolUrl(json) {
        if (!json) {
            return '';
        }

        const bytes = new TextEncoder().encode(json);
        let binary = '';
        bytes.forEach((byte) => {
            binary += String.fromCharCode(byte);
        });

        const encoded = btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/u, '');
        return `partdb-bpac:print,${encoded}`;
    }
}
