<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The second factor — a TABLE in this module, never columns on `users`.
 *
 * That was the owner's call and it is the better one twice over: a module may
 * not patch core's schema (CLAUDE.md), and a shop that decides it does not want
 * any of this uninstalls the module and the secrets go with it. Columns bolted
 * onto `users` would outlive the feature and sit there for years holding
 * credentials nothing reads.
 *
 * ONE ROW PER USER, and its existence is not the same as its being on:
 * `confirmed_at` is what arms it. Enrolment is two acts — the shop shows a
 * secret, the person proves they can read a code from it — and a row written by
 * the first one must lock nobody out if they close the tab, put the phone down,
 * or never manage to scan anything at all. So an unconfirmed row is an
 * abandoned attempt: it costs nothing, the next attempt replaces it, and every
 * question anywhere else in this module asks about `confirmed_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_guard_two_factor', function (Blueprint $table) {
            $table->id();

            // Unique because a person has one second factor. Cascading because
            // this row is nothing but a property of that account — the opposite
            // of the audit trail, which deliberately keeps no foreign key: a
            // record of what somebody did must outlive them, a credential of
            // theirs must not.
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            // The shared secret, base32 as the authenticator apps want it,
            // ENCRYPTED at rest. It has to be decryptable — TOTP is a sum both
            // sides compute, so unlike a password there is no hashing it — and
            // that is exactly why it is worth encrypting: a stolen table dump
            // without the app key is not a working second factor.
            $table->text('secret');

            // The way back when the phone is gone. Encrypted, and stored as
            // what they are rather than as hashes: the secret beside them is
            // already decryptable, so hashing these would protect nothing the
            // same key does not already protect — and being able to show the
            // list again is what stops the shop keeping it in a text file.
            $table->text('recovery_codes')->nullable();

            // Armed. Until this is set the row is an enrolment nobody finished.
            $table->timestamp('confirmed_at')->nullable();

            // The last 30-second step a code was accepted for. A TOTP code
            // stays valid for its whole step and a little either side, so
            // without this number the same six digits work twice — which is
            // the difference between a second factor and a second password
            // somebody read over your shoulder.
            $table->unsignedBigInteger('last_timestep')->nullable();

            // Evidence, for the person themselves: "used 3 minutes ago" on a
            // login they do not remember making is the whole point of showing
            // it. Not the same question as `confirmed_at`.
            $table->timestamp('last_used_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_guard_two_factor');
    }
};
