import { ref } from 'vue';

const isNewTicketModalOpen = ref(false);

export function useNewTicketModal() {
    function openNewTicketModal() {
        isNewTicketModalOpen.value = true;
    }

    function closeNewTicketModal() {
        isNewTicketModalOpen.value = false;
    }

    return {
        isNewTicketModalOpen,
        openNewTicketModal,
        closeNewTicketModal,
    };
}
