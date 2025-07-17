 document.addEventListener('DOMContentLoaded', function() {
            const govModal = document.getElementById('gov-fullscreen-modal');
            const govModalImg = govModal.querySelector('.gov-fullscreen-img');
            const govCloseBtn = govModal.querySelector('.gov-fullscreen-close');
            const govPrevBtn = govModal.querySelector('.gov-prev-btn');
            const govNextBtn = govModal.querySelector('.gov-next-btn');
            const govGalleryImages = document.querySelectorAll('#gouvernorat-gallery-section ul li img');
            
            let govCurrentIndex = 0;
            let touchStartX = 0;
            let touchEndX = 0;
    
            function openGovModal(index) {
                govCurrentIndex = index;
                govModalImg.src = govGalleryImages[index].src;
                govModalImg.alt = govGalleryImages[index].alt || '';
                govModal.classList.add('active');
                document.body.classList.add('gov-modal-open');
            }
    
            function closeGovModal() {
                govModal.classList.remove('active');
                document.body.classList.remove('gov-modal-open');
            }
    
            function showGovPrevImage() {
                govCurrentIndex = (govCurrentIndex - 1 + govGalleryImages.length) % govGalleryImages.length;
                govModalImg.src = govGalleryImages[govCurrentIndex].src;
                govModalImg.alt = govGalleryImages[govCurrentIndex].alt || '';
            }
    
            function showGovNextImage() {
                govCurrentIndex = (govCurrentIndex + 1) % govGalleryImages.length;
                govModalImg.src = govGalleryImages[govCurrentIndex].src;
                govModalImg.alt = govGalleryImages[govCurrentIndex].alt || '';
            }
    
            govGalleryImages.forEach((img, index) => {
                img.addEventListener('click', () => openGovModal(index));
            });
    
            govCloseBtn.addEventListener('click', closeGovModal);
            govPrevBtn.addEventListener('click', showGovPrevImage);
            govNextBtn.addEventListener('click', showGovNextImage);
    
            govModal.addEventListener('click', function(e) {
                if (!govModalImg.contains(e.target) && !govPrevBtn.contains(e.target) && !govNextBtn.contains(e.target)) {
                    closeGovModal();
                }
            });
    
            document.addEventListener('keydown', function(e) {
                if (!govModal.classList.contains('active')) return;
                if (e.key === 'Escape') closeGovModal();
                if (e.key === 'ArrowLeft') showGovPrevImage();
                if (e.key === 'ArrowRight') showGovNextImage();
            });
    
            govModal.addEventListener('touchstart', function(e) {
                touchStartX = e.changedTouches[0].screenX;
            });
    
            govModal.addEventListener('touchend', function(e) {
                touchEndX = e.changedTouches[0].screenX;
                handleSwipeGesture();
            });
    
            function handleSwipeGesture() {
                if (touchStartX - touchEndX > 50) {
                    showGovNextImage();
                } else if (touchEndX - touchStartX > 50) {
                    showGovPrevImage();
                }
            }
        });