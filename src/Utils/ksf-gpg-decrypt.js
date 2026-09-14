/**
 * KSF GPG Key Manager - Complete Browser Implementation
 *
 * Supports:
 * - New key generation
 * - Import existing keys (from Kleopatra, GPG, etc.)
 * - Keyservers for public key lookup
 * - OAuth login + GPG password
 * - Zero-knowledge architecture
 */

class KsfGpgKeyManager {

    /**
     * Generate and register a new key pair.
     */
    async generateAndRegister(email, password, name = '') {
        const { privateKey, publicKey } = await openpgp.generateKey({
            type: 'rsa',
            rsaBits: 4096,
            userIDs: [{ name, email }],
            passphrase: password,
        });

        const blob = await this.encryptForStorage(privateKey, password);
        const verifier = await this.hashPassword(password);

        await this.apiRegister({ email, verifier, blob, publicKey });

        return { publicKey, privateKey };
    }

    /**
     * Import existing GPG key (from Kleopatra, command line, etc.)
     */
    async importExistingKey(email, password) {
        // User pastes their existing key
        const privateKeyArmored = await this.promptPrivateKey();
        const publicKeyArmored = await this.promptPublicKey();

        // Try to decrypt the private key to verify password
        try {
            const decryptedKey = await openpgp.decryptKey({
                privateKey: await openpgp.readPrivateKey({ armoredKey: privateKeyArmored }),
                passphrase: password,
            });

            if (!decryptedKey) {
                throw new Error('Invalid password for this key');
            }
        } catch (err) {
            throw new Error('Could not decrypt private key. Wrong password?');
        }

        // Encrypt for storage with OUR password (different from original if user chose)
        const blob = await this.encryptForStorage(privateKeyArmored, password);
        const verifier = await this.hashPassword(password);

        await this.apiRegister({ email, verifier, blob, publicKey: publicKeyArmored });

        return { publicKey: publicKeyArmored };
    }

    /**
     * Login: OAuth auth + GPG password to decrypt key.
     */
    async login(oauthToken, email, gpgPassword) {
        // Step 1: OAuth authentication (verify identity)
        const authResponse = await fetch('/api/auth/oauth/verify', {
            headers: { Authorization: `Bearer ${oauthToken}` }
        });
        if (!authResponse.ok) throw new Error('OAuth failed');

        // Step 2: Get salt for password hashing
        const { salt } = await this.apiGetSalt(email);

        // Step 3: Hash password and verify
        const verifier = await this.hashPasswordWithSalt(gpgPassword, salt);
        const loginResponse = await this.apiLogin({ email, verifier });
        if (!loginResponse.ok) throw new Error('GPG password incorrect');

        // Step 4: Download encrypted blob
        const { encrypted_blob } = await this.apiGetBlob(email);

        // Step 5: Decrypt private key locally
        const privateKeyArmored = await this.decryptFromStorage(encrypted_blob, gpgPassword);

        return new KsfGpgSession({
            email,
            privateKey: await openpgp.readPrivateKey({ armoredKey: privateKeyArmored }),
        });
    }

    /**
     * Look up public key from keyservers.
     */
    async lookupPublicKey(email) {
        // Try keys.openpgp.org first
        try {
            const response = await fetch(
                `https://keys.openpgp.org/vks/v1/by-email/${encodeURIComponent(email)}`,
                { headers: { Accept: 'application/openpgp-key' } }
            );

            if (response.ok) {
                const armoredKey = await response.text();
                return await openpgp.readPublicKey({ armoredKey });
            }
        } catch (err) {
            console.warn('keys.openpgp.org lookup failed:', err);
        }

        // Fallback to keybase.io
        try {
            const response = await fetch(
                `https://keybase.io/_/api/1.0/user/lookup.json?username=${encodeURIComponent(email)}`
            );
            const data = await response.json();
            if (data.them && data.them.public_keys && data.them.public_keys.primary) {
                const armoredKey = data.them.public_keys.primary.bundle;
                return await openpgp.readPublicKey({ armoredKey });
            }
        } catch (err) {
            console.warn('keybase.io lookup failed:', err);
        }

        throw new Error(`Public key not found for ${email}`);
    }

    /**
     * Encrypt private key for server storage.
     * Password NEVER leaves browser.
     */
    async encryptForStorage(privateKeyArmored, password) {
        const salt = crypto.getRandomValues(new Uint8Array(32));
        const iv = crypto.getRandomValues(new Uint8Array(12));

        const key = await this.deriveKey(password, salt);

        const ciphertext = await crypto.subtle.encrypt(
            { name: 'AES-GCM', iv },
            key,
            new TextEncoder().encode(privateKeyArmored)
        );

        return JSON.stringify({
            v: 1, // format version
            salt: this.bytesToBase64(salt),
            iv: this.bytesToBase64(iv),
            ct: this.bytesToBase64(new Uint8Array(ciphertext)),
        });
    }

    /**
     * Decrypt private key from server storage.
     */
    async decryptFromStorage(blob, password) {
        const { salt, iv, ct } = JSON.parse(blob);

        const key = await this.deriveKey(password, this.base64ToBytes(salt));

        const decrypted = await crypto.subtle.decrypt(
            { name: 'AES-GCM' },
            key,
            this.base64ToBytes(ct)
        );

        return new TextDecoder().decode(decrypted);
    }

    /**
     * Derive encryption key from password.
     */
    async deriveKey(password, salt) {
        const keyMaterial = await crypto.subtle.importKey(
            'raw',
            new TextEncoder().encode(password),
            'PBKDF2',
            false,
            ['deriveKey']
        );

        return crypto.subtle.deriveKey(
            {
                name: 'PBKDF2',
                salt,
                iterations: 100000,
                hash: 'SHA-256',
            },
            keyMaterial,
            { name: 'AES-GCM', length: 256 },
            false,
            ['encrypt', 'decrypt']
        );
    }

    /**
     * Hash password for authentication (server stores this).
     */
    async hashPassword(password) {
        const salt = crypto.getRandomValues(new Uint8Array(16));
        const encoder = new TextEncoder();

        const key = await crypto.subtle.importKey(
            'raw',
            encoder.encode(password),
            'PBKDF2',
            false,
            ['deriveBits']
        );

        const bits = await crypto.subtle.deriveBits(
            { name: 'PBKDF2', salt, iterations: 100000, hash: 'SHA-256' },
            key,
            256
        );

        return this.bytesToBase64(salt) + ':' + this.bytesToBase64(new Uint8Array(bits));
    }

    /**
     * Hash password with known salt.
     */
    async hashPasswordWithSalt(password, saltBase64) {
        const salt = this.base64ToBytes(saltBase64);
        const encoder = new TextEncoder();

        const key = await crypto.subtle.importKey(
            'raw',
            encoder.encode(password),
            'PBKDF2',
            false,
            ['deriveBits']
        );

        const bits = await crypto.subtle.deriveBits(
            { name: 'PBKDF2', salt, iterations: 100000, hash: 'SHA-256' },
            key,
            256
        );

        return this.bytesToBase64(new Uint8Array(bits));
    }

    // Utility methods
    bytesToBase64(bytes) {
        return btoa(String.fromCharCode(...bytes));
    }

    base64ToBytes(base64) {
        return Uint8Array.from(atob(base64), c => c.charCodeAt(0));
    }

    promptPrivateKey() {
        return prompt('Paste your private key (armored):');
    }

    promptPublicKey() {
        return prompt('Paste your public key (armored):');
    }

    // API methods (implement these to match your server)
    async apiRegister(data) { throw new Error('Implement'); }
    async apiGetSalt(email) { throw new Error('Implement'); }
    async apiLogin(data) { throw new Error('Implement'); }
    async apiGetBlob(email) { throw new Error('Implement'); }
}

/**
 * Active GPG session after login.
 */
class KsfGpgSession {
    constructor(options) {
        this.email = options.email;
        this.privateKey = options.privateKey; // openpgp.Key object
    }

    /**
     * Encrypt data for multiple recipients.
     */
    async encryptForRecipients(plaintext, recipients) {
        // recipients = [{ id: 'user_5', email: 'a@b.com', publicKey: openpgp.Key }]
        const sessionKey = crypto.getRandomValues(new Uint8Array(32]);

        // Encrypt data with session key
        const encryptedData = await this.encryptWithSessionKey(plaintext, sessionKey);

        // Encrypt session key for each recipient
        const encryptedKeys = {};
        for (const recipient of recipients) {
            const msg = await openpgp.encrypt({
                message: await openpgp.createMessage({ binary: sessionKey }),
                encryptionKeys: recipient.publicKey,
            });
            encryptedKeys[recipient.id] = msg;
        }

        return { data: encryptedData, keys: encryptedKeys };
    }

    /**
     * Decrypt data encrypted to us.
     */
    async decrypt(encrypted) {
        const { data, keys } = encrypted;

        // Get our encrypted session key
        const ourKey = keys[this.email];
        if (!ourKey) {
            throw new Error('No key for this user');
        }

        // Decrypt session key
        const message = await openpgp.readMessage({ armoredMessage: ourKey });
        const { data: sessionKey } = await openpgp.decrypt({
            message,
            decryptionKeys: this.privateKey,
        });

        // Decrypt data
        return this.decryptWithSessionKey(data, sessionKey);
    }

    async encryptWithSessionKey(plaintext, sessionKey) {
        const iv = crypto.getRandomValues(new Uint8Array(12));
        const key = await crypto.subtle.importKey('raw', sessionKey, 'AES-GCM', false, ['encrypt']);

        const ciphertext = await crypto.subtle.encrypt(
            { name: 'AES-GCM', iv },
            key,
            new TextEncoder().encode(plaintext)
        );

        const combined = new Uint8Array(iv.length + ciphertext.byteLength);
        combined.set(iv);
        combined.set(new Uint8Array(ciphertext), iv.length);

        return btoa(String.fromCharCode(...combined));
    }

    async decryptWithSessionKey(base64Data, sessionKey) {
        const data = this.base64ToBytes(base64Data);
        const iv = data.slice(0, 12);
        const ciphertext = data.slice(12);

        const key = await crypto.subtle.importKey('raw', sessionKey, 'AES-GCM', false, ['decrypt']);

        const decrypted = await crypto.subtle.decrypt({ name: 'AES-GCM', iv }, key, ciphertext);

        return new TextDecoder().decode(decrypted);
    }

    base64ToBytes(base64) {
        return Uint8Array.from(atob(base64), c => c.charCodeAt(0));
    }

    close() {
        this.privateKey = null;
    }
}

/**
 * Key storage using IndexedDB.
 */
class KsfGpgKeyStore {
    constructor() {
        this.dbName = 'ksf_gpg_keys';
    }

    async open() {
        return new Promise((resolve, reject) => {
            const request = indexedDB.open(this.dbName, 1);
            request.onerror = () => reject(request.error);
            request.onsuccess = () => resolve(request.result);
            request.onupgradeneeded = (event) => {
                const db = event.target.result;
                if (!db.objectStoreNames.contains('keys')) {
                    db.createObjectStore('keys', { keyPath: 'id' });
                }
                if (!db.objectStoreNames.contains('sessions')) {
                    db.createObjectStore('sessions', { keyPath: 'email' });
                }
            };
        });
    }

    async storeKey(id, keyData) {
        const db = await this.open();
        const tx = db.transaction('keys', 'readwrite');
        tx.objectStore('keys').put({ id, ...keyData, updatedAt: Date.now() });
        return tx.complete;
    }

    async getKey(id) {
        const db = await this.open();
        return new Promise((resolve, reject) => {
            const tx = db.transaction('keys', 'readonly');
            const request = tx.objectStore('keys').get(id);
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
        });
    }

    async clear() {
        const db = await this.open();
        const tx = db.transaction('keys', 'readwrite');
        tx.objectStore('keys').clear();
        return tx.complete;
    }
}

if (typeof module !== 'undefined' && module.exports) {
    module.exports = { KsfGpgKeyManager, KsfGpgSession, KsfGpgKeyStore };
}