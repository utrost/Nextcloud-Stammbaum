<script setup>
import { t, title } from '../i18n.js';
import DateField from './DateField.vue';
defineProps({ draft: Object, management: Boolean, releasing: Boolean });
</script>
<template>
  <div class="field-grid">
    <label for="given-name"
      >{{ t('Given name')
      }}<input id="given-name" v-model="draft.givenName" maxlength="200" autocomplete="off"
    /></label>
    <label for="family-name"
      >{{ t('Family name')
      }}<input id="family-name" v-model="draft.familyName" maxlength="200" autocomplete="off"
    /></label>
  </div>
  <label v-if="!draft.givenName.trim() && !draft.familyName.trim()" class="check"
    ><input v-model="draft.confirmUnnamed" type="checkbox" />{{
      t('I confirm that no name is known.')
    }}</label
  >
  <label for="display-note"
    >{{ t('Display note')
    }}<textarea id="display-note" v-model="draft.displayNote" rows="2" maxlength="2000"></textarea>
  </label>
  <div class="field-grid">
    <label for="living-status"
      >{{ t('Living status')
      }}<select id="living-status" v-model="draft.livingStatus">
        <option v-for="v in ['unknown', 'living', 'deceased']" :key="v" :value="v">{{ t(title(v)) }}</option>
      </select></label
    >
    <label for="sex"
      >{{ t('Sex')
      }}<select id="sex" v-model="draft.sex">
        <option v-for="v in ['unknown', 'female', 'male', 'other']" :key="v" :value="v">
          {{ t(title(v)) }}
        </option>
      </select></label
    >
  </div>
  <label v-if="management" for="person-visibility"
    >{{ t('Visibility')
    }}<select id="person-visibility" v-model="draft.visibility">
      <option value="members">{{ t('Tree members') }}</option>
      <option value="management">{{ t('Management only') }}</option>
    </select></label
  >
  <label v-if="releasing && draft.visibility === 'members'" class="check"
    ><input v-model="draft.confirmRelease" type="checkbox" />{{
      t('Release these current values to tree members. Private historical values stay restricted.')
    }}</label
  >
  <DateField v-model="draft.birthDate" :label="t('Birth')" prefix="birth" />
  <label for="birth-place"
    >{{ t('Birth place') }}<input id="birth-place" v-model="draft.birthPlace" maxlength="500"
  /></label>
  <DateField v-model="draft.deathDate" :label="t('Death')" prefix="death" />
  <label for="death-place"
    >{{ t('Death place') }}<input id="death-place" v-model="draft.deathPlace" maxlength="500"
  /></label>
</template>
