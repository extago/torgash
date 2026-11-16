<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rules by conditions (PLAN §74): "category → multiplier" becomes an ordered
 * list of "conditions → what the goods earn and whether points pay for them".
 *
 * - `rules` — tried top to bottom, the first that matches and SAYS something
 *   about an axis (earning, paying) decides that axis.
 * - `groups` — a customer group's multiplier: WHO buys, not what; multiplies
 *   whatever the rules worked out.
 * - `products` — a product's own answer, which beats every rule; the price in
 *   points becomes optional beside it.
 * - `orders.terms` — what each line earned BY, kept with the waiting number,
 *   so a rule edited later does not move an order already placed.
 *
 * The old multipliers are carried over: a category's as a rule (the deepest
 * first — "the nearest category wins" was the old law, and the list's order is
 * the new one), a product's as its own answer, a group's as it was.
 */
return new class extends Migration
{
    public function up(): void
    {
        $old = DB::table('m_points_rules')->get();

        Schema::drop('m_points_rules');

        Schema::create('m_points_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name', 190)->nullable();
            $table->integer('position')->default(0);
            $table->boolean('enabled')->default(true);
            // categories / products / feature_values / groups — lists of ids;
            // price_min / price_max — minor units. Every one present must hold.
            $table->jsonb('conditions')->default('{}');
            // default | multiplier | percent | fixed | none
            $table->string('earn_mode', 16)->default('default');
            $table->decimal('earn_value', 12, 2)->nullable();
            // default | allowed | forbidden | limit
            $table->string('spend_mode', 16)->default('default');
            $table->unsignedSmallInteger('spend_percent')->nullable();
            $table->timestamps();

            $table->index(['enabled', 'position']);
        });

        Schema::create('m_points_groups', function (Blueprint $table) {
            $table->unsignedBigInteger('group_id')->primary();
            $table->decimal('multiplier', 6, 2)->default(1);
            $table->timestamps();
        });

        Schema::table('m_points_products', function (Blueprint $table) {
            $table->unsignedInteger('points_price')->nullable()->change();
            $table->string('earn_mode', 16)->default('default');
            $table->decimal('earn_value', 12, 2)->nullable();
            $table->string('spend_mode', 16)->default('default');
            $table->unsignedSmallInteger('spend_percent')->nullable();
        });

        Schema::table('m_points_orders', function (Blueprint $table) {
            $table->jsonb('terms')->nullable();
        });

        $now = now();
        $parents = DB::table('m_catalog_categories')->pluck('parent_id', 'id')->all();
        $depth = function (int $id) use ($parents): int {
            $depth = 0;

            while (($id = (int) ($parents[$id] ?? 0)) > 0 && $depth < 50) {
                $depth++;
            }

            return $depth;
        };

        $position = 0;

        foreach ($old->where('kind', 'category')->sortByDesc(fn ($rule) => $depth((int) $rule->target_id)) as $rule) {
            DB::table('m_points_rules')->insert([
                'position' => $position++,
                'conditions' => json_encode(['categories' => [(int) $rule->target_id]]),
                'earn_mode' => (float) $rule->multiplier > 0 ? 'multiplier' : 'none',
                'earn_value' => (float) $rule->multiplier > 0 ? $rule->multiplier : null,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        foreach ($old->where('kind', 'group') as $rule) {
            DB::table('m_points_groups')->insert(['group_id' => $rule->target_id, 'multiplier' => $rule->multiplier, 'created_at' => $now, 'updated_at' => $now]);
        }

        foreach ($old->where('kind', 'product') as $rule) {
            $mode = (float) $rule->multiplier > 0 ? 'multiplier' : 'none';
            $values = ['earn_mode' => $mode, 'earn_value' => $mode === 'multiplier' ? $rule->multiplier : null, 'updated_at' => $now];

            if (! DB::table('m_points_products')->where('product_id', $rule->target_id)->update($values)
                && DB::table('m_catalog_products')->where('id', $rule->target_id)->exists()) {
                DB::table('m_points_products')->insert($values + ['product_id' => $rule->target_id, 'created_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        $rules = DB::table('m_points_rules')->orderBy('position')->get();
        $groups = DB::table('m_points_groups')->get();
        $products = DB::table('m_points_products')->whereIn('earn_mode', ['multiplier', 'none'])->get();

        Schema::table('m_points_orders', fn (Blueprint $table) => $table->dropColumn('terms'));

        DB::table('m_points_products')->whereNull('points_price')->delete();
        Schema::table('m_points_products', function (Blueprint $table) {
            $table->dropColumn(['earn_mode', 'earn_value', 'spend_mode', 'spend_percent']);
            $table->unsignedInteger('points_price')->nullable(false)->change();
        });

        Schema::drop('m_points_groups');
        Schema::drop('m_points_rules');

        Schema::create('m_points_rules', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 16);
            $table->unsignedBigInteger('target_id');
            $table->decimal('multiplier', 6, 2)->default(1);
            $table->timestamps();

            $table->unique(['kind', 'target_id']);
        });

        // What the old shape can say: one category (or group, or product) and a multiplier.
        $rows = [];

        foreach ($rules as $rule) {
            $conditions = json_decode((string) $rule->conditions, true) ?: [];

            if (in_array($rule->earn_mode, ['multiplier', 'none'], true) && array_keys($conditions) === ['categories']) {
                foreach ((array) $conditions['categories'] as $id) {
                    $rows['category:'.$id] ??= ['kind' => 'category', 'target_id' => (int) $id, 'multiplier' => $rule->earn_mode === 'none' ? 0 : $rule->earn_value];
                }
            }
        }

        foreach ($groups as $group) {
            $rows['group:'.$group->group_id] = ['kind' => 'group', 'target_id' => $group->group_id, 'multiplier' => $group->multiplier];
        }

        foreach ($products as $product) {
            $rows['product:'.$product->product_id] = ['kind' => 'product', 'target_id' => $product->product_id, 'multiplier' => $product->earn_mode === 'none' ? 0 : $product->earn_value];
        }

        if ($rows !== []) {
            DB::table('m_points_rules')->insert(array_values($rows));
        }
    }
};
